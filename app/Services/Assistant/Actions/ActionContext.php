<?php

namespace App\Services\Assistant\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Para quién y desde dónde se propone o se hace un cambio: el dueño, la respuesta del chat donde sale la tarjeta y los
 * archivos de su pregunta (por ejemplo, la foto de la factura que queda como comprobante). Cuenta las propuestas de una
 * respuesta (como mucho ActionDesk::MAX_PER_ANSWER).
 */
final class ActionContext
{
    public int $proposals = 0;

    /** @param array<int, array> $files los archivos de la pregunta (assistant_messages.attachments) */
    public function __construct(
        public readonly User $user,
        public readonly ?int $replyId,
        public readonly array $files,
        public readonly CarbonImmutable $now,
    ) {
    }

    /** El archivo N de la pregunta (desde 1), o null. */
    public function file(?int $number): ?array
    {
        return $number !== null && $number >= 1 ? ($this->files[$number - 1] ?? null) : null;
    }
}
