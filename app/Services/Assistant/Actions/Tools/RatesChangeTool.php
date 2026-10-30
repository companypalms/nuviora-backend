<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Finance\ExchangeRates;

/**
 * Cambiar las tasas del día (lo mismo que "Tasa y pagos › Tasa del dólar": ExchangeRates::set). Las que no se dicen
 * quedan como están. Si una cambia más de 20 %, la tarjeta avisa (un cero de más o de menos).
 */
class RatesChangeTool extends ChangeTool
{
    /** Desde cuánto cambio se avisa (en %). */
    public const BIG_CHANGE = 20;

    public function __construct(private ExchangeRates $rates)
    {
    }

    public function name(): string
    {
        return 'proponer_tasas';
    }

    public function description(): string
    {
        return 'Propone cambiar las tasas del día (bolívares por dólar o por euro): BCV dólar, BCV euro y Binance. Pon solo las que el dueño quiere cambiar; las demás en null (quedan como están). El dueño confirma en una tarjeta con antes y después.';
    }

    public function parameters(): array
    {
        $rate = fn (string $what) => ['type' => ['number', 'null'], 'description' => "{$what} en bolívares, o null para dejarla como está."];

        return [
            'type' => 'object',
            'properties' => ['bcv_usd' => $rate('BCV del dólar'), 'bcv_eur' => $rate('BCV del euro'), 'binance_usd' => $rate('Binance (dólar)')],
            'required' => ExchangeRates::KEYS,
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando el cambio de tasas…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $current = $this->rates->current();
        $new = [];
        $lines = [];
        $warnings = [];
        foreach (ExchangeRates::KEYS as $key) {
            $label = ExchangeRates::LABELS[$key];
            $given = $args[$key] ?? null;
            if ($given !== null && (!is_numeric($given) || (float) $given <= 0)) {
                throw new ToolProblem("La tasa {$label} tiene que ser un número mayor que 0.");
            }
            $value = $given !== null ? round((float) $given, 2) : $current[$key];
            if ($value === null) {
                throw new ToolProblem("Todavía no hay una tasa {$label} guardada: pregúntale cuánto es.");
            }
            $new[$key] = $value;
            $changes = $current[$key] === null || abs($value - $current[$key]) > 0.0001;
            $lines[] = Proposal::line($label, self::bs($value), $changes && $current[$key] !== null ? self::bs($current[$key]) : null)
                + ($changes ? [] : ['same' => true]);
            if ($changes && $current[$key] > 0 && abs($value - $current[$key]) / $current[$key] * 100 > self::BIG_CHANGE) {
                $warnings[] = "{$label} cambia " . number_format(abs($value - $current[$key]) / $current[$key] * 100, 0, ',', '.') . ' %. Revisa que no sobre ni falte un cero.';
            }
        }
        if (array_filter($lines, fn ($l) => empty($l['same'])) === []) {
            throw new ToolProblem('Las tasas ya están así: no hay nada que cambiar.');
        }
        $lines = array_map(fn ($l) => empty($l['same']) ? $l : ['label' => $l['label'], 'value' => $l['value'] . ' (igual)'], $lines);
        $changed = array_column(array_filter($lines, fn ($l) => isset($l['before'])), 'label');

        return new Proposal(
            'Cambiar las tasas del día',
            'Cambiar la tasa ' . implode(', ', $changed ?: ['de hoy']) . ': ' . implode(' · ', array_map(fn ($l) => "{$l['label']} {$l['value']}", $lines)),
            $lines,
            $new,
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $this->rates->set($payload, $context->user->id);

        return new Outcome('Tasas guardadas: ' . implode(' · ', array_map(fn ($k) => ExchangeRates::LABELS[$k] . ' ' . self::bs($payload[$k]), ExchangeRates::KEYS)) . '.',
            ['label' => 'Ver las tasas', 'to' => '/admin/tasa-y-pagos']);
    }

    private static function bs(float $value): string
    {
        return 'Bs ' . number_format($value, 2, ',', '.');
    }
}
