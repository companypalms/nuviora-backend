<?php

namespace App\Services\Assistant\Actions;

/** Cómo terminó un cambio confirmado: qué se hizo y dónde verlo. PHP puro. */
final class Outcome
{
    /**
     * @param array{label: string, to: string}|null $link
     * @param array{label: string, value: string, note: string}|null $secret algo que el dueño ve UNA sola vez al confirmar
     *        (la contraseña de un usuario nuevo): no se guarda en la tarjeta ni lo ve la IA (paso 7).
     */
    public function __construct(public readonly string $text, public readonly ?array $link = null, public readonly ?array $secret = null)
    {
    }

    public function toArray(): array
    {
        return ['text' => $this->text, 'link' => $this->link];
    }
}
