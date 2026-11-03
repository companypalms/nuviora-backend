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

    public function approve(CreativeBatch $batch, ?int $userId): CreativeBatch
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

    /**
     * `.95` (dueño: hay batches ya hechos por el líder, en carpetas de Drive, que deberían estar en la banca, "sin
     * hipótesis"): cargar uno ya hecho. Recibe sus números (B y C) como al aprobar (nunca se repiten, tampoco con los
     * nombres que ya están en Meta) y queda en producción mientras se suben sus videos; EditorWork::finishImport lo pasa
     * a la banca. Sin plazo ni hipótesis: no cuenta para la racha, "a tiempo" ni "a la primera".
     *
     * @param array{product_id: int, angle_code: ?string, editor_id: int, concept: ?string, pieces: int} $data
     */
    public function import(array $data, int $userId): CreativeBatch
    {
        return DB::transaction(function () use ($data, $userId) {
            $number = self::nextBatchNumber();
            $piece = self::nextPieceNumber();
            $now = now();
            $batch = CreativeBatch::create([
                'product_id' => $data['product_id'], 'number' => $number, 'status' => 'production', 'kind' => 'new_concept', 'source' => 'imported',
                'angle_code' => $data['angle_code'], 'concept' => $data['concept'], 'format' => 'video', 'created_by' => $userId,
                'editor_id' => $data['editor_id'], 'assigned_at' => $now, 'assigned_by' => $userId, 'started_at' => $now,
                'approved_at' => $now, 'approved_by' => $userId, 'status_at' => $now, 'status_by' => $userId,
            ]);
            for ($i = 0; $i < $data['pieces']; $i++) {
                CreativeBatchPiece::create(['creative_batch_id' => $batch->id, 'number' => $piece++, 'position' => $i + 1, 'format' => 'video']);
            }

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
