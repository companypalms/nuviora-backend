<?php

namespace App\Services\Assistant\Actions;

/**
 * Un cambio propuesto, listo para la tarjeta: el título, cada dato que se va a guardar (con lo que había antes, si
 * cambia algo que ya existía), los avisos y lo que se ejecuta al confirmar (payload). PHP puro.
 */
final class Proposal
{
    /**
     * @param array<int, array{label: string, value: string, before?: ?string}> $lines
     * @param array<int, string> $warnings
     * @param array $payload lo que usa ChangeTool::execute (ya validado)
     * @param array<int, string> $notes avisos de lo que cambia a cada rato (cuántas órdenes tiene, cuántas esperan): se
     *        muestran, pero no cuentan para la huella (si no, la tarjeta vencería con cada orden nueva). Paso 7.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly array $lines,
        public readonly array $payload,
        public readonly array $warnings = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * Si al confirmar la propuesta da distinto (algo cambió en el sistema), la tarjeta vence. 5 y 5.0 son lo mismo (al
     * guardarse en la base, 5.0 vuelve como 5). Las notas no cuentan.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->payload, $this->lines, $this->warnings], JSON_UNESCAPED_UNICODE));
    }

    public function card(): array
    {
        return ['title' => $this->title, 'lines' => $this->lines, 'warnings' => $this->warnings] + ($this->notes !== [] ? ['notes' => $this->notes] : []);
    }

    /** Una línea de la tarjeta; sin valor no se muestra. */
    public static function line(string $label, ?string $value, ?string $before = null): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $before !== null ? ['label' => $label, 'value' => $value, 'before' => $before] : ['label' => $label, 'value' => $value];
    }

    /** @param array<int, ?array> $lines */
    public static function lines(array $lines): array
    {
        return array_values(array_filter($lines));
    }
}
