<?php

namespace App\Services\Assistant\Actions;

/** Cómo terminó un cambio confirmado: qué se hizo y dónde verlo. PHP puro. */
final class Outcome
{
    /** @param array{label: string, to: string}|null $link */
    public function __construct(public readonly string $text, public readonly ?array $link = null)
    {
    }

    public function toArray(): array
    {
        return ['text' => $this->text, 'link' => $this->link];
    }
}
