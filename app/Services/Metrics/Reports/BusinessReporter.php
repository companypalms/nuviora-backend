<?php

namespace App\Services\Metrics\Reports;

use App\Models\BusinessReport;
use App\Services\Calendar\CalendarSettings;
use App\Services\Metrics\Filters;
use App\Services\Metrics\NumbersCheck;
use App\Services\Metrics\OwnerPanel;
use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El reporte del negocio (`.48b`, pedido del dueño del 2026-10-31: "los reportes me los debería dar mi agente de IA, con
 * datos 100 % correctos"). Como el análisis de Meta de la mañana:
 * 1. Los números salen del panel del dueño (sus mismas secciones y reglas) y de la revisión de los números (48a).
 * 2. La IA los explica (ReportWriter); si escribe un monto o un porcentaje que no está en los datos, queda un aviso
 *    (TextCheck). Sin IA, el texto lo arma una plantilla con los mismos números.
 * 3. Se guarda como una versión del período ("Volver a generar" crea otra).
 * Sale solo cada mañana (metrics:report, 07:15) en los días de las revisiones del calendario. Nada se manda a nadie.
 */
class BusinessReporter
{
    /** "Volver a generar": como mucho estas veces por día (entre los tres tipos). */
    public const MAX_RUNS_PER_DAY = 10;

    public function __construct(private OwnerPanel $panel, private NumbersCheck $check, private ?ReportWriter $writer = null)
    {
    }

    public function run(string $type, ?int $userId = null, ?CarbonImmutable $day = null): BusinessReport
    {
        $now = CarbonImmutable::now();
        $day ??= $now;
        [$from, $to] = ReportPeriods::for($type, $day);
        // El mensual se compara con el mes anterior completo; el diario y el semanal, con los mismos días justo antes
        $period = $type === 'monthly'
            ? Period::fromPreset('last_month', null, null, $day->endOfDay()->min($now))
            : Period::fromPreset('custom', $from->toDateString(), $to->toDateString(), $now);
        $all = new Filters();
        $panel = [
            'sales' => $this->panel->sales($period, $all),
            'money' => $this->panel->money($period, $all),
            'losses' => $this->panel->losses($period, $all),
            'sellers' => $this->panel->team($period, $all, 'sellers'),
            'agencies' => $this->panel->team($period, $all, 'agencies'),
            'alerts' => $this->panel->alerts($period, $all),
        ];
        $facts = ReportFacts::build($type, $from->toDateString(), $to->toDateString(), ReportPeriods::label($from, $to), $panel, $this->check->run($period));
        $input = ReportFacts::input($facts);

        $row = ['status' => 'ok', 'text' => null, 'model' => null, 'tokens_in' => null, 'tokens_out' => null, 'error' => null, 'warnings' => []];
        if (!config('services.openai.key')) {
            $local = app()->environment('local');
            $row['status'] = $local ? 'example' : 'no_ai';
            $row['text'] = ReportWriter::template($facts, $local);
            $row['error'] = $local ? null : 'La IA no está configurada en este servidor (falta OPENAI_API_KEY): el texto lo armó el sistema con los mismos números.';
        } else {
            try {
                $ai = ($this->writer ?? new ReportWriter())->ask($input);
                $row = array_merge($row, ['text' => $ai['text'], 'model' => $ai['model'], 'tokens_in' => $ai['tokens_in'], 'tokens_out' => $ai['tokens_out']]);
                $row['warnings'] = TextCheck::unknown(json_encode($ai['text'], JSON_UNESCAPED_UNICODE), $input);
            } catch (RuntimeException $e) {
                report($e);
                $row['status'] = 'error';
                $row['text'] = ReportWriter::template($facts);
                $row['error'] = 'La IA no pudo escribir el reporte: el texto lo armó el sistema con los mismos números. (' . $e->getMessage() . ')';
            }
        }

        return DB::transaction(function () use ($type, $from, $to, $facts, $row, $userId) {
            $previous = BusinessReport::where('type', $type)->where('period_from', $from->toDateString())->where('period_to', $to->toDateString())
                ->lockForUpdate()->max('version');

            return BusinessReport::create($row + [
                'type' => $type, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                'version' => ((int) $previous) + 1, 'facts' => $facts, 'requested_by' => $userId,
            ]);
        });
    }

    /** Los reportes que salen solos hoy, según las revisiones del calendario. */
    public static function dueToday(?CarbonImmutable $day = null): array
    {
        $rules = app(CalendarSettings::class)->rules();

        return ReportPeriods::dueOn($day ?? CarbonImmutable::now(), (int) ($rules['review_week']['weekday'] ?? 4),
            array_map(fn ($r) => (bool) ($r['on'] ?? true), $rules));
    }

    /** ¿Cuántas veces más se puede tocar "Volver a generar" hoy? */
    public static function runsLeft(): int
    {
        return max(0, self::MAX_RUNS_PER_DAY - BusinessReport::whereNotNull('requested_by')->whereDate('created_at', now()->toDateString())->count());
    }
}
