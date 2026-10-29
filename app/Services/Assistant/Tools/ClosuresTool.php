<?php

namespace App\Services\Assistant\Tools;

use App\Models\AgencyWeeklyClosure;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Present;
use App\Services\Delivery\Closures\ClosureList;
use App\Services\Metrics\Period;
use Carbon\CarbonImmutable;

/** Los cierres semanales de las agencias (Cierres de agencias): su estado y sus montos. */
class ClosuresTool implements Tool
{
    private const STATUS_WORDS = [
        'sin_archivo' => AgencyWeeklyClosure::PENDING_FILE,
        'en_revision' => AgencyWeeklyClosure::PENDING_REVIEW,
        'listo_para_pagar' => AgencyWeeklyClosure::READY,
        'pagado' => AgencyWeeklyClosure::PAID,
    ];

    public function __construct(private ClosureList $closures, private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'cierres_agencias';
    }

    public function description(): string
    {
        return 'Los cierres semanales de las agencias (de lunes a domingo): su estado (sin archivo, archivo recibido, procesando, en revisión, listo para pagar, pagado), cuántas carreras, cuánto esperaba el sistema, cuánto cobra la agencia, la diferencia y lo aprobado. Se puede filtrar por agencia, por estado o por la semana (el lunes con que empieza). Sin filtros, los más recientes.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'agencia' => ['type' => ['string', 'null'], 'description' => 'El nombre de la agencia. null = todas.'],
                'estado' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::STATUS_WORDS), null], 'description' => 'Solo los de ese estado. null = todos.'],
                'semana' => ['type' => ['string', 'null'], 'description' => 'El lunes de la semana, AAAA-MM-DD. null = las últimas.'],
            ],
            'required' => ['agencia', 'estado', 'semana'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Revisando los cierres de las agencias…';
    }

    public function run(array $args): ToolResult
    {
        $agencyId = null;
        if (!empty($args['agencia'])) {
            $found = $this->lookup->findPeople('agencies', (string) $args['agencia'], Period::fromPreset('month', null, null, CarbonImmutable::now()));
            if (count($found) !== 1) {
                throw new ToolProblem($found === [] ? "No encontré la agencia \"{$args['agencia']}\"."
                    : 'Hay varias agencias con ese nombre: ' . implode(', ', array_column($found, 'name')) . '. Pregúntale al dueño cuál.');
            }
            $agencyId = $found[0]['id'];
        }
        $week = !empty($args['semana']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['semana'])
            ? CarbonImmutable::parse($args['semana'])->startOfWeek()->toDateString() : null;
        $rows = $this->closures->list($agencyId, self::STATUS_WORDS[$args['estado'] ?? ''] ?? null, $week || $agencyId ? 30 : 20, $week);
        if ($rows === []) {
            return ToolResult::problem('No hay cierres con esos filtros.');
        }
        $money = fn ($v) => $v !== null ? Present::value((float) $v, 'money') : null;

        return new ToolResult(['cierres' => array_map(fn ($c) => array_filter([
            'agencia' => $c['agency'],
            'semana' => $c['week'],
            'estado' => $c['status_label'],
            'carreras' => $c['trips_count'],
            'esperaba_el_sistema' => $money($c['expected_total']),
            'cobra_la_agencia' => $money($c['reported_total']),
            'diferencia' => $money($c['difference']),
            'aprobado' => $money($c['final_approved_total']),
            'pagado_el' => $c['paid_at'] ? substr($c['paid_at'], 0, 10) : null,
            'error' => $c['processing_error'],
        ], fn ($v) => $v !== null), $rows)], [
            [
                'type' => 'table',
                'title' => 'Cierres de agencias',
                'columns' => [
                    ['key' => 'expected', 'label' => 'Esperado', 'format' => 'money'],
                    ['key' => 'reported', 'label' => 'Cobra', 'format' => 'money'],
                    ['key' => 'approved', 'label' => 'Aprobado', 'format' => 'money'],
                    ['key' => 'trips', 'label' => 'Carreras', 'format' => 'count'],
                ],
                'rows' => array_map(fn ($c) => ['name' => (string) $c['agency'], 'group' => "{$c['week']} · {$c['status_label']}", 'low_data' => false,
                    'values' => ['expected' => $c['expected_total'], 'reported' => $c['reported_total'], 'approved' => $c['final_approved_total'], 'trips' => $c['trips_count']]], $rows),
            ],
            ['type' => 'link', 'label' => 'Abrir cierres de agencias', 'to' => '/admin/cierres-agencias'],
        ]);
    }
}
