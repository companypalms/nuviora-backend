<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar un encargo y repartir los números (Etapa 5, paso 4a). Los números siguen al más alto que ya existe en el
 * sistema o en los nombres de Meta, y no se repiten entre productos (la tabla no deja dos iguales).
 */
class BatchQueue
{
    public function __construct(private DecisionSettings $settings = new DecisionSettings())
    {
    }

    public function approve(CreativeBatch $batch, int $userId): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $userId) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'proposed') {
                throw ValidationException::withMessages(['status' => 'Este encargo ya no está para aprobar.']);
            }
            $pieces = $batch->pieces()->get();
            if ($pieces->isEmpty()) {
                throw ValidationException::withMessages(['pieces' => 'Agrega al menos una pieza antes de aprobar.']);
            }
            $number = self::nextBatchNumber();
            $piece = self::nextPieceNumber();
            foreach ($pieces as $p) {
                $p->forceFill(['number' => $piece++])->save();
            }
            $now = now();
            $batch->forceFill([
                'number' => $number, 'status' => 'approved', 'approved_at' => $now, 'approved_by' => $userId, 'status_at' => $now, 'status_by' => $userId,
                'position' => (int) CreativeBatch::whereIn('status', QueueRules::QUEUE)->max('position') + 1,
                'due_date' => $batch->due_date ?? now(config('app.timezone'))->addDays((int) $this->settings->get()['due_days'])->toDateString(),
            ])->save();

            return $batch->fresh('pieces');
        });
    }

    /** Las piezas que se agregan a un encargo ya aprobado reciben su número enseguida. */
    public static function numberPiece(CreativeBatchPiece $piece): void
    {
        DB::transaction(fn () => $piece->forceFill(['number' => self::nextPieceNumber()])->save());
    }

    private static function nextBatchNumber(): int
    {
        $meta = DB::table('meta_adsets')->whereNotNull('batch_code')->distinct()->pluck('batch_code')->map(fn ($c) => QueueRules::batchNumber($c));
        $own = CreativeBatch::whereNotNull('number')->lockForUpdate()->max('number');

        return QueueRules::next($meta->push($own));
    }

    private static function nextPieceNumber(): int
    {
        $meta = DB::table('meta_ads')->whereNotNull('creative_code')->distinct()->pluck('creative_code')->map(fn ($c) => QueueRules::pieceNumber($c));
        $own = CreativeBatchPiece::whereNotNull('number')->lockForUpdate()->max('number');

        return QueueRules::next($meta->push($own));
    }
}
