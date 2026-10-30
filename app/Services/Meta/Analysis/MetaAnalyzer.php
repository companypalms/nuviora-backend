<?php

namespace App\Services\Meta\Analysis;

use App\Models\MetaAnalysis;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Queue\QueueProposer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El análisis de campañas de cada mañana (Etapa 5, paso 3a): a las 07:00 (meta:analyze) o con "Volver a analizar".
 * 1. Las reglas del dueño (Paso 2) dicen qué pasa con cada producto, ciudad y creativo.
 * 2. Con eso se arma la lista urgente (UrgentList: el orden y los montos son de las reglas).
 * 3. La IA explica y redacta (AnalysisWriter); si escribe un monto que no está en los datos, queda un aviso (MoneyCheck).
 * 4. Se guarda como una versión del día; lo que el dueño ya marcó en la versión anterior se conserva.
 * 5. Paso 4b: las reglas proponen los encargos que faltan y la IA los redacta (QueueProposer).
 * Nada se cambia en Meta ni se manda a nadie.
 */
class MetaAnalyzer
{
    /** "Volver a analizar": como mucho estas veces por día. */
    public const MAX_RUNS_PER_DAY = 10;

    public function __construct(private ?MetaDecisions $decisions = null, private ?AnalysisWriter $writer = null)
    {
    }

    public function run(?int $userId = null, ?string $date = null): MetaAnalysis
    {
        $date ??= now(config('app.timezone'))->toDateString();
        $productIds = self::products();
        $decisions = ($this->decisions ?? new MetaDecisions())->compute($productIds);
        $facts = AnalysisFacts::build($decisions, self::names($decisions));
        $urgent = UrgentList::build($facts);
        $input = AnalysisFacts::input($facts);

        $row = ['status' => 'ok', 'text' => null, 'model' => null, 'tokens_in' => null, 'tokens_out' => null, 'error' => null, 'warnings' => []];
        if (!$facts) {
            $row['text'] = ['resumen' => 'No hay campañas activas con datos para analizar.', 'productos' => []];
        } elseif (!config('services.openai.key')) {
            $row['status'] = app()->environment('local') ? 'example' : 'no_ai';
            $row['text'] = app()->environment('local') ? AnalysisWriter::example($facts, $urgent) : null;
            $row['error'] = app()->environment('local') ? null : 'La IA no está configurada en este servidor (falta OPENAI_API_KEY): la lista y los datos son los de tus reglas.';
        } else {
            try {
                $ai = ($this->writer ?? new AnalysisWriter())->ask($input);
                $row = array_merge($row, ['text' => $ai['text'], 'model' => $ai['model'], 'tokens_in' => $ai['tokens_in'], 'tokens_out' => $ai['tokens_out']]);
                $row['warnings'] = MoneyCheck::unknown(json_encode($ai['text'], JSON_UNESCAPED_UNICODE), $input);
            } catch (RuntimeException $e) {
                report($e);
                $row['status'] = 'error';
                $row['error'] = 'La IA no pudo escribir el análisis. La lista y los datos son los de tus reglas. (' . $e->getMessage() . ')';
            }
        }

        $analysis = DB::transaction(function () use ($date, $facts, $urgent, $row, $userId) {
            $previous = MetaAnalysis::where('date', $date)->lockForUpdate()->orderByDesc('version')->first();
            $keys = array_column($urgent, 'key');

            return MetaAnalysis::create($row + [
                'date' => $date, 'version' => ($previous->version ?? 0) + 1, 'facts' => $facts, 'urgent' => $urgent,
                // Lo que ya marcó en la versión anterior del día sigue marcado si el punto sigue en la lista
                'marks' => array_intersect_key($previous->marks ?? [], array_flip($keys)) ?: null,
                'requested_by' => $userId,
            ]);
        });
        // Paso 4b: los encargos que faltan quedan en la cola para aprobar (si algo falla, el análisis queda igual)
        try {
            (new QueueProposer($this->decisions))->run($userId);
        } catch (\Throwable $e) {
            report($e);
        }

        return $analysis;
    }

    /** ¿Cuántas veces más se puede tocar "Volver a analizar" hoy? */
    public static function runsLeft(): int
    {
        $today = now(config('app.timezone'))->toDateString();

        return max(0, self::MAX_RUNS_PER_DAY - MetaAnalysis::where('date', $today)->whereNotNull('requested_by')->count());
    }

    /** Los productos a analizar: los que tienen campañas activas o gastaron algo en los últimos 7 días. */
    public static function products(): array
    {
        $since = now(config('app.timezone'))->subDays(7)->toDateString();
        $spent = DB::table('meta_insights_daily as d')->join('meta_campaigns as c', 'c.meta_id', '=', 'd.campaign_meta_id')
            ->where('d.level', 'campaign')->where('d.date', '>=', $since)->where('d.spend', '>', 0)->whereNotNull('c.product_id')
            ->distinct()->pluck('c.product_id');
        $active = DB::table('meta_campaigns')->whereNotNull('product_id')->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->distinct()->pluck('product_id');

        return $spent->merge($active)->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Los nombres de las campañas y de los batches activos de unas decisiones (para el análisis y el asistente). */
    public static function names(array $decisions): array
    {
        $campaigns = [];
        $adsets = [];
        foreach ($decisions as $p) {
            $campaigns = array_merge($campaigns, array_map('strval', array_keys($p['campaigns'])));
            $adsets = array_merge($adsets, array_map('strval', array_keys($p['adsets'] ?? [])));
        }

        return [
            'campaigns' => DB::table('meta_campaigns')->whereIn('meta_id', $campaigns ?: ['-'])->pluck('name', 'meta_id')->all(),
            'adsets' => DB::table('meta_adsets')->whereIn('meta_id', $adsets ?: ['-'])->where('effective_status', 'ACTIVE')->whereNull('missing_since')
                ->pluck('name', 'meta_id')->all(),
        ];
    }
}
