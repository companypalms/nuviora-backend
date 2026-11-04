<?php

namespace App\Services\Meta\Queue;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\MetaCreative;
use App\Services\Meta\Decisions\DecisionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobar un encargo y repartir los números (Etapa 5, paso 4a). Los números siguen al más alto que ya existe en el
 * sistema o en los nombres de Meta, y no se repiten entre productos (la tabla no deja dos iguales).
 */
class BatchQueue
{
    /** `.99`: lo que cambia en un batch ya hecho que son variaciones de un creativo → lo que prueba cada pieza (PieceRules::TESTS). */
    public const IMPORT_CHANGES = ['hook' => 'winner_hook', 'format' => 'format', 'mixed' => 'iteration'];

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
     * `.99` (el líder: "son variaciones de un producto… yo tendría que poner 3 variantes realizadas del creativo 2 del
     * batch 1, o se monta normal"): si son variaciones de un creativo que ya estuvo en Meta, queda como iteración de ese
     * creativo (como los encargos de iterar), con lo que cambia (el hook, el formato o varias cosas) en cada pieza. Sin
     * ángulo elegido, toma el del creativo si está en la ficha.
     *
     * @param array{product_id: int, angle_code: ?string, editor_id: int, concept: ?string, pieces: int, iterates_id?: ?int, changes?: ?string} $data
     */
    public function import(array $data, int $userId): CreativeBatch
    {
        $iterates = null;
        if (!empty($data['iterates_id'])) {
            $iterates = MetaCreative::where('product_id', $data['product_id'])->find((int) $data['iterates_id'], ['id', 'angle_code']);
            if (!$iterates) {
                throw ValidationException::withMessages(['iterates_id' => 'Ese creativo no es de este producto: elige otro.']);
            }
            if (!isset(self::IMPORT_CHANGES[$data['changes'] ?? ''])) {
                throw ValidationException::withMessages(['changes' => 'Elige qué cambia en estas variaciones: el hook, el formato o varias cosas.']);
            }
            if (($data['angle_code'] ?? null) === null && $iterates->angle_code
                && AdAngle::where('product_id', $data['product_id'])->where('code', $iterates->angle_code)->whereNull('alias_of')->exists()) {
                $data['angle_code'] = $iterates->angle_code;
            }
        }

        return DB::transaction(function () use ($data, $userId, $iterates) {
            $number = self::nextBatchNumber();
            $piece = self::nextPieceNumber();
            $now = now();
            $batch = CreativeBatch::create([
                'product_id' => $data['product_id'], 'number' => $number, 'status' => 'production', 'kind' => $iterates ? 'iteration' : 'new_concept',
                'source' => 'imported', 'reference_creative_id' => $iterates?->id,
                'angle_code' => $data['angle_code'], 'concept' => $data['concept'], 'format' => 'video', 'created_by' => $userId,
                'editor_id' => $data['editor_id'], 'assigned_at' => $now, 'assigned_by' => $userId, 'started_at' => $now,
                'approved_at' => $now, 'approved_by' => $userId, 'status_at' => $now, 'status_by' => $userId,
            ]);
            for ($i = 0; $i < $data['pieces']; $i++) {
                CreativeBatchPiece::create(['creative_batch_id' => $batch->id, 'number' => $piece++, 'position' => $i + 1, 'format' => 'video',
                    'test' => $iterates ? self::IMPORT_CHANGES[$data['changes']] : null]);
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
