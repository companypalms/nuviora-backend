<?php

namespace App\Services\Meta\Analysis;

use App\Models\MetaAnalysis;
use App\Services\Meta\Changes\ChangeLessons;
use App\Services\Meta\Changes\MetaChanges;
use App\Services\Meta\Decisions\MetaDecisions;
use App\Services\Meta\Products\NewProductMissions;
use App\Services\Meta\Queue\QueueProposer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El análisis de campañas de cada día (Etapa 5, paso 3a): a las 00:30 (meta:analyze, `.85`) o con "Volver a analizar".
 * 1. Las reglas del dueño (Paso 2) dicen qué pasa con cada producto, ciudad y creativo.
 * 2. Con eso se arma la lista urgente (UrgentList: el orden y los montos son de las reglas).
 * 3. La IA explica y redacta (AnalysisWriter); si escribe un monto que no está en los datos, queda un aviso (MoneyCheck).
 * 4. Se guarda como una versión del día; lo que el dueño ya marcó en la versión anterior se conserva.
 * 5. Paso 4b: las reglas proponen los encargos que faltan y la IA los redacta (QueueProposer).
 * 5b. `.71`: lo que hay que iterar (y los ganadores) se le manda solo al editor líder como encargo aprobado (AnalysisSender).
 * 6. Paso 6c: los encargos de landing ya no se proponen solos (`.70`, el dueño las hace él): "Proponer ahora" en Landings.
 * Nada se cambia en Meta ni se manda a nadie.
 *
 * `.84` (2026-11-02): con "Volver a analizar" ($later), los pasos 5, 5b y "Lo que sabemos" se hacen después de responder,
 * para que la página no espere de más (en producción el servidor cortaba la espera y salía un error rojo, aunque el análisis
 * sí se guardaba). A las 00:30 se hace todo seguido.
 */
class MetaAnalyzer
{
    /** "Volver a analizar": como mucho estas veces por día. */
    public const MAX_RUNS_PER_DAY = 10;

    public function __construct(private ?MetaDecisions $decisions = null, private ?AnalysisWriter $writer = null)
    {
    }

    public function run(?int $userId = null, ?string $date = null, bool $later = false): MetaAnalysis
    {
        $date ??= now(config('app.timezone'))->toDateString();
        $productIds = self::products();
        $decisions = ($this->decisions ?? new MetaDecisions())->compute($productIds);
        $facts = AnalysisFacts::build($decisions, self::names($decisions));
        // .56: lo que dicen los videos de los ganadores (transcritos en la .55)
        $facts = AnalysisFacts::withScripts($facts, \App\Services\Meta\Videos\Scripts::forCreatives(
            array_merge([], ...array_map(fn ($p) => array_column($p['creatives'], 'id'), $facts))));
        // .132: lo que pasó con los cambios de antes (el historial de la .131), para la IA y para cada misión
        $lessons = self::lessons($date);
        $facts = ChangeLessons::intoFacts($facts, $lessons);
        // .89: los encargos atrasados, para presionar al editor líder
        $late = \App\Services\Meta\Queue\LateBatches::find($decisions, $date, (int) (new \App\Services\Meta\Decisions\DecisionSettings())->get()['corrections_days']);
        // .89: y los testeos listos para lanzar (todos sus batches en la banca)
        $urgent = UrgentList::build($facts, $late, \App\Services\Meta\Queue\LateBatches::leader(), \App\Services\Meta\Queue\TestingLaunch::ready(),
            \App\Services\Meta\NameCheck::find()); // .89: y lo que no se puede reconocer por su nombre
        // .132: "la última vez" en cada misión, y el aviso si el mismo cambio no se cumplió 3 veces seguidas en una ciudad
        $urgent = ChangeLessons::missions($urgent, $facts, $lessons);
        array_push($urgent, ...ChangeLessons::notices($lessons, array_column($facts, 'product', 'product_id'), $date));
        // .118: si va tarde con el producto nuevo, es una misión de hoy
        if ($behind = $this->missionLate($date)) {
            $urgent[] = $behind;
        }
        // .125: si los datos de Meta son viejos, se avisa arriba de todo
        if ($stale = self::staleNotice()) {
            array_unshift($urgent, $stale);
        }
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
        if ($later) {
            \Illuminate\Support\defer(fn () => $this->afterwards($analysis, $userId, $late, $decisions));
        } else {
            $this->afterwards($analysis, $userId, $late, $decisions);
        }

        return $analysis;
    }

    /** Lo que sigue al análisis guardado: los encargos, lo que va al editor líder y "Lo que sabemos". Si algo falla, el análisis queda igual. */
    private function afterwards(MetaAnalysis $analysis, ?int $userId, array $late = [], array $decisions = []): void
    {
        // .89: el sistema también presiona: al editor líder, en su campanita, lo atrasado (una vez por día)
        try {
            \App\Services\Meta\Queue\LateBatches::remind($late, $analysis->date->toDateString());
        } catch (\Throwable $e) {
            report($e);
        }
        // Paso 4b y .71: los encargos que faltan. .88: lo que necesita cada producto y para cuándo, directo al editor líder
        // (primero la iteración de lo que hay que iterar, después lo que redacta la IA), sin tope de editores
        try {
            (new QueueProposer($this->decisions))->run($userId, $analysis);
        } catch (\Throwable $e) {
            report($e);
        }
        // .118: si ya hay banca y equipo, se abre la misión de lanzar un producto nuevo
        try {
            (new NewProductMissions())->evaluate($decisions, $analysis->date->toDateString(), QueueProposer::forecast()['verdict'] ?? null);
        } catch (\Throwable $e) {
            report($e);
        }
        // .66: el resumen de "Lo que sabemos" de los productos con batches testeados o notas nuevas
        try {
            app(\App\Services\Meta\Products\ProductMemories::class)->refreshChanged();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** `.132`: el historial de cambios al día y lo medido. Si algo falla, el análisis sale igual (sin historial). */
    private static function lessons(string $date): array
    {
        try {
            $changes = app(MetaChanges::class);
            $changes->refreshIfStale();

            return $changes->lessons($date);
        } catch (\Throwable $e) {
            report($e);

            return ['campaigns' => [], 'products' => []];
        }
    }

    /** `.125`: el aviso de datos viejos, con la última lectura de las cuentas activas. Si algo falla, el análisis sale igual. */
    private static function staleNotice(): ?array
    {
        try {
            $synced = \App\Models\MetaAdAccount::where('is_active', true)->pluck('last_synced_at');
            if ($synced->isEmpty()) {
                return null;
            }
            // La cuenta que hace más tiempo no se lee manda (una que nunca se leyó, también)
            $last = $synced->contains(null) ? null : $synced->min();
            $tz = config('app.timezone');

            return UrgentList::stale($last !== null ? \Illuminate\Support\Carbon::parse($last)->setTimezone($tz)->toDateTimeString() : null, now($tz)->toDateTimeString(),
                (float) (new \App\Services\Meta\Decisions\DecisionSettings())->get()['sync_stale_hours']);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** `.118`: la misión de "vas tarde con el producto nuevo". Si algo falla, el análisis sale igual. */
    private function missionLate(string $date): ?array
    {
        try {
            return (new NewProductMissions())->lateItem($date);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
