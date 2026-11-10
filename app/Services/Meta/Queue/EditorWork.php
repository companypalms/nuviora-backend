<?php

namespace App\Services\Meta\Queue;

use App\Models\AdAngle;
use App\Models\CreativeBatch;
use App\Models\CreativeBatchDelivery;
use App\Models\CreativeBatchPiece;
use App\Models\MetaCreative;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileStore;
use App\Services\Files\Uploads;
use App\Services\Meta\Products\ProductModes;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que hacen los editores con la cola (Etapa 5, paso 5a): asignar, empezar, entregar y revisar, con los permisos de
 * EditorRules; y el encargo como lo ven ellos (todo el encargo, sin dinero). Lo usan sus pantallas (EditorQueueController)
 * y la Cola y banca del dueño (MetaQueueController).
 */
class EditorWork
{
    public const REVIEWS = ['pending' => 'Por revisar', 'approved' => 'Aprobada', 'returned' => 'Devuelta'];

    /** Lo que se carga para mostrar un encargo. */
    public const WITH = ['pieces', 'product:id,title,showable_name', 'persona', 'reference:id,label,tracking_id,creative_code', 'learningCity:id,name', 'editor:id,names,surnames',
        'deliveries.editor:id,names,surnames', 'deliveries.reviewer:id,names,surnames'];

    /** @return array{id: int, role: ?string} */
    public static function who(User $user): array
    {
        return ['id' => (int) $user->id, 'role' => $user->role?->description];
    }

    /** Los editores activos, para asignar: el líder primero. */
    public static function editors(): array
    {
        return User::with('role:id,description')->whereNull('deactivated_at')
            ->whereHas('role', fn ($q) => $q->whereIn('description', EditorRules::ROLES))->orderBy('names')->get(['id', 'names', 'surnames', 'role_id'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u), 'leader' => $u->role?->description === EditorRules::LEADER])
            ->sortByDesc('leader')->values()->all();
    }

    /**
     * `.94`: los editores con lo que tiene cada uno (encargos abiertos y atrasados) y el sugerido para el próximo
     * (EditorRules::suggest), para asignar sabiendo quién está más libre.
     *
     * @return list<array{id: int, name: string, leader: bool, open: int, late: int, suggested: bool}>
     */
    public static function editorsWithLoad(): array
    {
        $today = now(config('app.timezone'))->toDateString();
        // .99: lo de un producto en pausa, en liquidación o abandonado está en espera: no es carga de nadie
        $open = ProductModes::withoutHeld(CreativeBatch::whereIn('status', ['approved', 'production', 'review'])->whereNotNull('editor_id'))
            ->get(['editor_id', 'status', 'due_date', 'delivered_at'])->groupBy('editor_id');
        $editors = array_map(fn ($e) => $e + [
            'open' => count($open[$e['id']] ?? []),
            'late' => collect($open[$e['id']] ?? [])->filter(fn ($b) => $b->status !== 'review' && $b->delivered_at === null && $b->due_date
                && $b->due_date->toDateString() < $today)->count(),
        ], self::editors());
        $best = EditorRules::suggest($editors);

        return array_map(fn ($e) => $e + ['suggested' => $e['id'] === $best], $editors);
    }

    /**
     * Asignar (o cambiar de editor). Sin editor solo puede quedar lo que todavía no se empezó. `.94`: con "reference_id"
     * en $options, la referencia sugerida para partir (null la quita; sin la llave, queda como estaba).
     *
     * @param array{reference_id?: ?int} $options
     */
    public function assign(CreativeBatch $batch, ?int $editorId, User $actor, array $options = []): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $editorId, $actor, $options) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('assign', $actor, $batch);
            if ($editorId !== null && !in_array($editorId, array_column(self::editors(), 'id'), true)) {
                throw ValidationException::withMessages(['editor_id' => 'Elige un editor activo (los usuarios con el rol "Editor líder" o "Editor de video").']);
            }
            if ($editorId === null && $batch->status !== 'approved') {
                throw ValidationException::withMessages(['editor_id' => 'Un encargo que ya se empezó tiene que quedar con un editor: elige a quién pasárselo.']);
            }
            $batch->forceFill(['editor_id' => $editorId, 'assigned_at' => $editorId ? now() : null, 'assigned_by' => $editorId ? $actor->id : null])->save();
            if (array_key_exists('reference_id', $options)) {
                $ref = $options['reference_id'];
                if ($ref !== null && !\App\Models\CreativeReference::whereKey($ref)->whereNull('archived_at')->exists()) {
                    throw ValidationException::withMessages(['reference_id' => 'Esa referencia ya no está en la biblioteca: elige otra.']);
                }
                $batch->forceFill(['suggested_reference_id' => $ref])->save();
            }

            return $batch;
        });
    }

    public function start(CreativeBatch $batch, User $actor): CreativeBatch
    {
        return DB::transaction(function () use ($batch, $actor) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('start', $actor, $batch);
            $now = now();
            $batch->forceFill(['status' => 'production', 'started_at' => $batch->started_at ?? $now, 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $batch;
        });
    }

    /**
     * `.90` (dueño: "que se suban todos los archivos en el sistema en vez de utilizar Google Drive"): subir el archivo de
     * una pieza al almacén (el editor del encargo, mientras lo puede entregar). Queda con el nombre de su anuncio
     * ("C0457 POSP.mp4") y es de la próxima versión. Se confirma con Uploads::confirm y se entrega con deliver.
     */
    public function uploadPiece(CreativeBatch $batch, CreativeBatchPiece $piece, User $actor, string $name, string $mime, int $size): array
    {
        $this->authorize('deliver', $actor, $batch);
        abort_unless((int) $piece->creative_batch_id === (int) $batch->id, 404, 'Esa pieza no es de este encargo: vuelve a abrirlo.');
        // .91: sin botón "Empezar" (regla 11: lo que el sistema puede saber solo, no lleva botón): el primer archivo lo empieza
        if ($batch->status === 'approved') {
            $now = now();
            $batch->forceFill(['status' => 'production', 'started_at' => $batch->started_at ?? $now, 'status_at' => $now, 'status_by' => $actor->id])->save();
        }
        $names = QueueRules::metaNames($batch->number, $batch->angle_code, [$piece->number]);

        return app(Uploads::class)->start([
            'kind' => 'piece', 'name' => $name, 'display' => $names['ads'][0] ?? $piece->code(), 'product_id' => $batch->product_id,
            'batch' => $names['adset'] ?? $batch->code(), 'piece_id' => $piece->id, 'version' => (int) $batch->deliveries()->max('version') + 1,
        ], $mime, $size, $actor);
    }

    /** `.95`: el video de una pieza de un batch ya hecho que se está cargando (solo el líder). */
    public function uploadImport(CreativeBatch $batch, CreativeBatchPiece $piece, User $actor, string $name, string $mime, int $size): array
    {
        self::importing($batch, $actor);
        abort_unless((int) $piece->creative_batch_id === (int) $batch->id, 404, 'Ese video no es de este batch: vuelve a empezar la carga.');
        $names = QueueRules::metaNames($batch->number, $batch->angle_code, [$piece->number]);

        return app(Uploads::class)->start([
            'kind' => 'piece', 'name' => $name, 'display' => $names['ads'][0] ?? $piece->code(), 'product_id' => $batch->product_id,
            'batch' => $names['adset'] ?? $batch->code(), 'piece_id' => $piece->id, 'version' => 1,
        ], $mime, $size, $actor);
    }

    /**
     * `.95`: terminar la carga de un batch ya hecho: cada pieza con su video (y su hook, si se sabe) y el batch a la banca,
     * como lo que entrega el líder. Si una ciudad lo necesita ya, sale la misión "Lanza B…" (BankArrival).
     *
     * @param list<array{id: int, file_id: ?int, hook?: ?string}> $pieces
     */
    public function finishImport(CreativeBatch $batch, User $actor, array $pieces): CreativeBatch
    {
        $batch = DB::transaction(function () use ($batch, $actor, $pieces) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            self::importing($batch, $actor);
            $given = collect($pieces)->keyBy('id');
            $files = StoredFile::whereIn('id', $given->pluck('file_id')->filter()->all() ?: [0])->get()->keyBy('id');
            $all = $batch->pieces()->get();
            foreach ($all as $p) {
                $f = $files[$given[$p->id]['file_id'] ?? 0] ?? null;
                if (!$f || $f->status !== 'ready' || (int) $f->creative_batch_piece_id !== (int) $p->id) {
                    throw ValidationException::withMessages(['pieces' => "{$p->code()}: falta su video (vuelve a subirlo)."]);
                }
            }
            foreach ($all as $p) {
                $f = $files[$given[$p->id]['file_id']];
                $p->forceFill(['file_id' => $f->id, 'hook' => trim((string) ($given[$p->id]['hook'] ?? '')) ?: null,
                    'format' => str_starts_with((string) $f->mime, 'image/') ? 'image' : 'video',
                    // .99: variaciones de un creativo: cada pieza salió de la iteración de ese creativo
                    'source' => $batch->reference_creative_id ? 'iteration' : $p->source])->save();
            }
            $batch->forceFill(['status' => 'bank', 'status_at' => now(), 'status_by' => $actor->id])->save();

            return $batch;
        });
        BankArrival::safely($batch);

        return $batch->fresh('pieces');
    }

    /** `.95`: la carga se dejó a medias (se cerró la ventana): el batch se descarta; sus números no se vuelven a usar. */
    public function cancelImport(CreativeBatch $batch, User $actor): void
    {
        self::importing($batch, $actor);
        $batch->forceFill(['status' => 'discarded', 'discard_reason' => 'Carga sin terminar', 'status_at' => now(), 'status_by' => $actor->id])->save();
    }

    /** Solo el líder, y solo un batch ya hecho que se está cargando. */
    private static function importing(CreativeBatch $batch, User $actor): void
    {
        if (self::who($actor)['role'] !== EditorRules::LEADER) {
            throw new HttpResponseException(response()->json(['status' => false, 'message' => 'Cargar batches ya hechos lo hace el editor líder.'], 403));
        }
        if ($batch->source !== 'imported' || $batch->status !== 'production') {
            throw ValidationException::withMessages(['batch' => 'Este batch ya no se está cargando: vuelve a abrir la pantalla.']);
        }
    }

    /**
     * Entregar: el archivo (`.90`, con el almacén encendido; si no, un enlace de Drive) y su hook por cada pieza, y la
     * ficha (concepto y ángulo). Pasa a "En revisión" como la versión siguiente (V01, V02…). En una corrección, la pieza
     * que no se volvió a subir sigue con su archivo. `.92`: cada pieza dice de dónde salió (una referencia de la biblioteca,
     * idea propia o, en una iteración, el ganador); si no lo dice, sigue con lo de su entrega anterior. `.98`: en el encargo de
     * iterar un ganador, todas sus piezas quedan como "iteración" de ese ganador, sin importar lo que mande la pantalla.
     *
     * @param array{concept: string, angle: string, notes?: ?string, pieces: list<array{id: int, link?: ?string, file_id?: ?int, hook: ?string, source?: ?string, reference_id?: ?int}>} $data
     */
    public function deliver(CreativeBatch $batch, User $actor, array $data): CreativeBatchDelivery
    {
        $delivery = DB::transaction(function () use ($batch, $actor, $data) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize('deliver', $actor, $batch);
            $pieces = $batch->pieces()->get();
            $given = collect($data['pieces'])->keyBy(fn ($p) => (int) $p['id']);
            $files = FileStore::on();
            // .90: el archivo nuevo de cada pieza, o el que ya tenía (si no lo volvió a subir)
            $fileOf = fn (CreativeBatchPiece $p) => (int) ($given->get($p->id)['file_id'] ?? 0) ?: (int) $p->file_id;
            $ready = $files ? StoredFile::whereIn('id', $pieces->map($fileOf)->filter()->values())->where('kind', 'piece')->where('status', 'ready')->get()->keyBy('id') : collect();
            // .92: de dónde salió cada pieza (lo nuevo, o lo de su entrega anterior)
            // .98 (dueño: "que el sistema lo haga automáticamente"): en el encargo de iterar un ganador, cada pieza sale de ese
            // ganador; queda fija, no la elige el editor
            $sourceOf = fn (CreativeBatchPiece $p) => $batch->reference_creative_id ? 'iteration' : (($given->get($p->id)['source'] ?? null) ?: $p->source);
            $refOf = fn (CreativeBatchPiece $p) => $sourceOf($p) === 'reference' ? ((int) ($given->get($p->id)['reference_id'] ?? 0) ?: (int) $p->source_reference_id) : null;
            $refs = \App\Models\CreativeReference::whereIn('id', $pieces->map($refOf)->filter()->values())->get()->keyBy('id');
            $errors = [];
            if ($given->keys()->diff($pieces->pluck('id'))->isNotEmpty()) {
                $errors['pieces'] = 'Hay piezas que no son de este encargo: vuelve a abrirlo.';
            }
            foreach ($pieces->values() as $i => $p) {
                $g = $given->get($p->id);
                $name = $p->code() ?? 'Pieza ' . ($i + 1);
                if ($files && (int) ($ready->get($fileOf($p))?->creative_batch_piece_id) !== (int) $p->id) {
                    $errors["pieces.{$i}.file"] = "{$name}: sube el video o la imagen de esta pieza.";
                } elseif (!$files && EditorRules::driveFileId($g['link'] ?? null) === null) {
                    $errors["pieces.{$i}.link"] = "{$name}: " . EditorRules::linkProblem($g['link'] ?? null);
                }
                // .133 (dueño, 2026-11-10: "no quiero que le pida a nadie escribir el hook del video, toma mucho tiempo"; "debe
                // elegir el concepto o formato… si es un testimonio, venta directa, UGC, animación con IA"): el formato, de la lista
                if (!isset(EditorRules::deliveryStyles()[(string) ($g['style'] ?? '')])) {
                    $errors["pieces.{$i}.style"] = "{$name}: elige su formato (testimonio, UGC, venta directa…).";
                }
                $source = $sourceOf($p);
                if (!isset(ReferenceLibrary::SOURCES[(string) $source])) {
                    $errors["pieces.{$i}.source"] = "{$name}: di de dónde salió (una referencia de la biblioteca o idea propia).";
                } elseif ($source === 'reference' && !$refs->has($refOf($p))) {
                    $errors["pieces.{$i}.source"] = "{$name}: elige la referencia de la que salió (o «Idea propia»).";
                } elseif ($source === 'iteration' && !$batch->reference_creative_id) {
                    $errors["pieces.{$i}.source"] = "{$name}: este encargo no es la iteración de un ganador: elige una referencia o «Idea propia».";
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $rows = $pieces->map(fn (CreativeBatchPiece $p) => ['piece_id' => $p->id, 'code' => $p->code(),
                'link' => $files ? null : trim($given[$p->id]['link']), 'file_id' => $files ? $fileOf($p) : null,
                'style' => (string) $given[$p->id]['style'], 'source' => $sourceOf($p), 'reference_id' => $refOf($p)])->values()->all();
            $delivery = CreativeBatchDelivery::create([
                'creative_batch_id' => $batch->id, 'version' => (int) $batch->deliveries()->max('version') + 1, 'editor_id' => $actor->id,
                'concept' => trim($data['concept']), 'angle' => trim($data['angle']), 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'pieces' => $rows,
            ]);
            foreach ($pieces as $i => $p) {
                // .133: el formato que eligió el editor (el de verdad: así "Lo que sabemos" aprende qué formato funciona)
                $p->forceFill(['link' => $rows[$i]['link'], 'file_id' => $rows[$i]['file_id'], 'style' => $rows[$i]['style'],
                    'source' => $rows[$i]['source'], 'source_reference_id' => $rows[$i]['reference_id']])->save();
            }
            $now = now();
            // .89 (dueño, 2026-11-03: "él mismo lo aprueba, pero debe quedar en el sistema que esos creativos los hizo él"):
            // lo que entrega el editor líder pasa directo a la banca; la entrega queda a su nombre (sus números lo cuentan)
            $own = self::who($actor)['role'] === EditorRules::LEADER;
            if ($own) {
                $delivery->forceFill(['review' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => $now])->save();
            }
            $batch->forceFill(['status' => $own ? 'bank' : 'review', 'status_at' => $now, 'status_by' => $actor->id,
                'started_at' => $batch->started_at ?? $now, 'delivered_at' => $batch->delivered_at ?? $now])->save();

            return $delivery;
        });
        if ($delivery->review === 'approved') {
            BankArrival::safely($batch->fresh());
        }

        return $delivery;
    }

    /** Revisar la entrega pendiente: aprobada pasa a la banca; devuelta, con un comentario, vuelve a producción. */
    public function review(CreativeBatch $batch, User $actor, bool $approve, ?string $comment): CreativeBatch
    {
        $batch = DB::transaction(function () use ($batch, $actor, $approve, $comment) {
            $batch = CreativeBatch::lockForUpdate()->findOrFail($batch->id);
            $pending = $batch->deliveries()->where('review', 'pending')->first();
            $this->authorize('review', $actor, $batch, $pending?->editor_id);
            $comment = trim((string) $comment);
            if (!$approve && mb_strlen($comment) < 10) {
                throw ValidationException::withMessages(['comment' => 'Escribe qué hay que cambiar con algo que se vea: el segundo, el texto o la imagen (por ejemplo, "C0457, seg. 0–3: el texto tapa la cara").']);
            }
            $now = now();
            $pending->forceFill(['review' => $approve ? 'approved' : 'returned', 'reviewed_by' => $actor->id, 'reviewed_at' => $now, 'comment' => $comment ?: null])->save();
            $batch->forceFill(['status' => $approve ? 'bank' : 'production', 'status_at' => $now, 'status_by' => $actor->id])->save();

            return $batch;
        });
        // .89: si una ciudad lo necesita ya, misión en el análisis de hoy y aviso al dueño
        if ($approve) {
            BankArrival::safely($batch);
        }

        return $batch;
    }

    /**
     * Los encargos como los ven los editores: todo lo del encargo, sin dinero (ni el gasto, ni el resultado, ni el
     * aprendizaje, que puede traer montos), con la miniatura y los textos de la referencia y lo que puede hacer quien mira.
     *
     * @param Collection<int, CreativeBatch> $batches con self::WITH
     * @param array{id: int, role: ?string} $who
     */
    public static function present(Collection $batches, array $who): array
    {
        $angles = AdAngle::whereIn('product_id', $batches->pluck('product_id')->unique())->whereNotNull('name')->get(['product_id', 'code', 'name'])
            ->mapWithKeys(fn ($a) => ["{$a->product_id}:{$a->code}" => $a->name]);
        $refIds = $batches->pluck('reference_creative_id')->filter()->unique()->values()->all();
        $refs = self::references($refIds);
        // .56: lo que dice el video de cada referencia (si es un ganador transcrito)
        $scripts = \App\Services\Meta\Videos\Scripts::forCreatives($refIds);
        $productIds = $batches->pluck('product_id')->unique()->all();
        $profiles = \App\Services\Meta\Products\ProductProfiles::many($productIds);
        // .90: la ficha completa (todos los buyer personas y ángulos), las fotos del producto y el archivo de cada pieza
        $allAngles = \App\Services\Meta\Products\TestingPhase::anglesMany($productIds);
        $photos = StoredFile::photosOf($productIds);
        $pieceFiles = StoredFile::payloads($batches->flatMap(fn (CreativeBatch $b) => $b->pieces->pluck('file_id'))->all());
        $uploads = FileStore::on();

        // .69: el editor no ve precios ni montos en ningún texto (la ficha, la oferta, lo que escribió la IA, la referencia)
        $clean = fn (?string $v) => QueueRules::withoutPrices($v);

        return self::sorted($batches)->map(function (CreativeBatch $b) use ($angles, $refs, $scripts, $profiles, $who, $clean, $allAngles, $photos, $pieceFiles, $uploads) {
            $task = self::task($b, $angles["{$b->product_id}:{$b->angle_code}"] ?? null);
            // .128: en una variación de las reglas, el editor y el líder leen la hipótesis fija (BatchTask), no la de la IA
            $plain = $b->source === 'rules' && $b->kind === 'iteration';
            $out = [
                'id' => $b->id, 'code' => $b->code(), 'product_id' => $b->product_id, 'product' => $b->product?->showable_name ?: $b->product?->title,
                'status' => $b->status, 'status_label' => QueueRules::STATUSES[$b->status] ?? $b->status, 'status_at' => $b->status_at?->toIso8601String(),
                'position' => $b->position, 'kind' => $b->kind, 'kind_label' => QueueRules::KINDS[$b->kind] ?? $b->kind,
                'angle_code' => $b->angle_code, 'angle_name' => $angles["{$b->product_id}:{$b->angle_code}"] ?? null,
                // .63: lo que buscamos, lo que funcionó y lo que hay que mejorar; nada del proceso del dueño (ni la hipótesis, ni
                // la prioridad, ni la ciudad de prueba): el editor ve solo lo que necesita para hacer su trabajo
                'test_idea' => $clean($b->test_idea), 'goal' => $clean($b->goal), 'keep' => $clean($b->keep), 'improve' => $clean($b->improve),
                'concept' => $clean($b->concept), 'audience' => $clean($b->audience),
                // .93: de qué trata el ángulo, para tenerlo a la vista al revisar
                'angle_about' => $clean(collect($allAngles[$b->product_id] ?? [])->firstWhere('code', $b->angle_code)['hypothesis'] ?? null),
            ] + ($plain ? ['hypothesis' => $task['hypothesis'] ?? null] : self::hypothesisFor($who, $b->hypothesis)) + self::product($b, $profiles, $allAngles, $photos) + [
                'reference' => self::referenceName($b->reference), 'reference_thumbnail' => $refs[$b->reference_creative_id]['thumbnail'] ?? null,
                'reference_texts' => QueueRules::withoutPricesIn($refs[$b->reference_creative_id]['texts'] ?? []),
                'reference_script' => QueueRules::withoutPricesIn($scripts[$b->reference_creative_id] ?? null),
                'format' => $b->format, 'style' => $b->style, 'style_label' => $b->style ? (QueueRules::STYLES[$b->style] ?? $b->style) : null,
                'offer' => $clean($b->offer), 'deliverables' => $clean($b->deliverables), 'due_date' => $b->due_date?->toDateString(),
                // .81: qué prueba cada pieza y su hipótesis (por qué ese formato y el resultado son del dueño)
                'pieces' => $b->pieces->map(fn (CreativeBatchPiece $p) => ['id' => $p->id, 'code' => $p->code(), 'change' => $clean($p->change), 'format' => $p->format,
                    'link' => $p->link, 'file' => $pieceFiles[$p->file_id] ?? null, 'hook' => $p->hook, 'style' => $p->style, 'test_label' => PieceRules::TESTS[$p->test] ?? null,
                    'style_label' => PieceRules::formatLabel($p->style), 'hypothesis' => $clean($p->hypothesis),
                    // .92: de dónde salió (para partir de lo mismo en una corrección)
                    'source' => $p->source, 'reference_id' => $p->source_reference_id])->values()->all(),
                // .92: si es la iteración de un ganador, "Iteración de …" ya viene elegida en cada pieza
                'iteration_of' => $b->reference_creative_id ? (self::referenceName($b->reference) ?? 'el ganador') : null,
                // .95: un batch ya hecho que cargó el líder (de antes del sistema, sin hipótesis)
                'imported' => $b->source === 'imported',
                // .99: qué hay que hacer, en una frase ("3 variaciones de hook de C0146", "3 piezas nuevas del ángulo POSP")
                'task' => $task,
                'meta_names' => QueueRules::metaNames($b->number, $b->angle_code, $b->pieces->pluck('number')->all()),
                'uploads' => $uploads, // .90: se entrega subiendo el archivo (no con Drive)
            ] + self::fields($b, $who);
            $out = self::withoutAiTexts($out, $b);

            return $out + ['brief' => QueueRules::brief($out)];
        })->values()->all();
    }

    /**
     * `.128` (dueño, 2026-11-09: "el sistema no tiene que recomendarle absolutamente nada en cuanto a qué debe hacer él en cada
     * variación"; "el editor líder tampoco debe ver las recomendaciones"): de un encargo de las reglas no le llega a nadie lo
     * que redactó la IA, tampoco en los que ya estaban abiertos. En una variación quedan los textos fijos de BatchTask; en las
     * piezas nuevas, una línea general. Lo que escribe el dueño se ve tal cual.
     */
    public static function withoutAiTexts(array $out, CreativeBatch $b): array
    {
        if ($b->source !== 'rules') {
            return $out;
        }
        $out = array_replace($out, ['goal' => null, 'keep' => null, 'improve' => null, 'concept' => null, 'audience' => null,
            'test_idea' => $b->kind === 'iteration' ? null : $out['test_idea']]);
        $out['pieces'] = array_map(fn (array $p) => array_replace($p, ['change' => null, 'hypothesis' => null]), $out['pieces']);

        return $out;
    }

    /**
     * `.103`: cómo se llama el creativo de un encargo: su nombre en Meta y, si no lo tiene (un creativo viejo que se quedó
     * sin anuncios), su código.
     */
    public static function referenceName(?MetaCreative $c): ?string
    {
        if ($c === null) {
            return null;
        }
        $code = str_contains((string) $c->tracking_id, ':') ? substr((string) $c->tracking_id, strpos((string) $c->tracking_id, ':') + 1) : $c->tracking_id;

        return ($c->label ?: $code ?: $c->creative_code) ?: null;
    }

    /** `.99`: qué hay que hacer en el encargo (BatchTask), con lo que ya trae. */
    public static function task(CreativeBatch $b, ?string $angleName): ?array
    {
        return BatchTask::of(['kind' => $b->kind, 'angle_code' => $b->angle_code, 'angle_name' => $angleName,
            'iterates' => $b->reference_creative_id ? (self::referenceName($b->reference) ?? 'el ganador') : null,
            'imported' => $b->source === 'imported', 'testing' => $b->testing_round !== null, 'rules' => $b->source === 'rules',
            'pieces' => $b->pieces->map(fn (CreativeBatchPiece $p) => ['test' => $p->test, 'style' => $p->style, 'format' => $p->format])->all()]);
    }

    /**
     * `.64`: lo del producto que ve el editor (qué es, para qué sirve, cómo funciona, lo que NO es, la información clave y
     * las fotos) y el buyer persona de su encargo. `.90` (dueño, 2026-11-03: "cada editor debe poder saber de qué trata
     * cada buyer persona, de qué trata cada ángulo, cuál es el mecanismo de acción del producto, cuál es el producto"):
     * también todos los buyer personas y todos los ángulos (con de qué trata cada uno), sin el orden de fuerza, y las
     * fotos del producto. Nunca los aprendizajes ni los precios.
     *
     * @param array<int, array{profile: ?array, personas: list<array>}> $profiles ProductProfiles::many
     * @param array<int, list<array>> $angles TestingPhase::anglesMany
     * @param array<int, list<array>> $photos StoredFile::photosOf
     */
    public static function product(CreativeBatch $b, array $profiles, array $angles = [], array $photos = []): array
    {
        $rules = \App\Services\Meta\Products\ProductProfileRules::class;

        return [
            'product_info' => $rules::forEditor($profiles[$b->product_id]['profile'] ?? null),
            'persona' => $rules::personaForEditor($b->persona?->only(array_keys($rules::PERSONA_FIELDS))),
            'personas' => array_values(array_filter(array_map(fn ($p) => $rules::personaForEditor(array_intersect_key($p, $rules::PERSONA_FIELDS)),
                $profiles[$b->product_id]['personas'] ?? []))),
            'angles' => $rules::anglesForEditor($angles[$b->product_id] ?? []),
            'photos' => $photos[$b->product_id] ?? [],
        ];
    }

    /**
     * Lo de los editores en un encargo, para sus pantallas y para la del dueño: quién lo hace, sus entregas, si se lo
     * devolvieron y lo que puede hacer quien mira.
     *
     * @param array{id: int, role: ?string} $who
     */
    public static function fields(CreativeBatch $b, array $who): array
    {
        $pending = $b->deliveries->firstWhere('review', 'pending');
        $state = ['status' => $b->status, 'editor_id' => $b->editor_id, 'pending_by' => $pending?->editor_id];
        $last = $b->deliveries->first();
        // .90: el archivo de cada pieza de cada entrega (para verlo ahí mismo)
        $files = StoredFile::payloads($b->deliveries->flatMap(fn (CreativeBatchDelivery $d) => array_column((array) $d->pieces, 'file_id'))->all());
        // .92: de qué referencia salió cada pieza (para verla al revisar)
        $refs = ReferenceLibrary::brief($b->deliveries->flatMap(fn (CreativeBatchDelivery $d) => array_column((array) $d->pieces, 'reference_id'))->all());

        return [
            'editor_id' => $b->editor_id, 'editor' => $b->editor ? self::name($b->editor) : null,
            'assigned_at' => $b->assigned_at?->toIso8601String(), 'started_at' => $b->started_at?->toIso8601String(),
            'delivered_at' => $b->delivered_at?->toIso8601String(),
            'overdue' => $b->due_date !== null && $b->delivered_at === null && in_array($b->status, ['approved', 'production'], true)
                && $b->due_date->toDateString() < now(config('app.timezone'))->toDateString(),
            'returned' => $b->status === 'production' && $last?->review === 'returned' ? ['version' => $last->label(), 'comment' => $last->comment,
                'by' => $last->reviewer ? self::name($last->reviewer) : null] : null,
            'deliveries' => $b->deliveries->map(fn (CreativeBatchDelivery $d) => [
                'id' => $d->id, 'version' => $d->label(), 'editor' => $d->editor ? self::name($d->editor) : null, 'delivered_at' => $d->created_at?->toIso8601String(),
                'concept' => $d->concept, 'angle' => $d->angle, 'notes' => $d->notes,
                'pieces' => array_map(fn ($p) => $p + ['file' => $files[$p['file_id'] ?? 0] ?? null, 'reference' => $refs[$p['reference_id'] ?? 0] ?? null,
                    'source_label' => ReferenceLibrary::SOURCES[$p['source'] ?? ''] ?? null,
                    'style_label' => PieceRules::formatLabel($p['style'] ?? null)], (array) $d->pieces), // .133: el formato que eligió
                'review' => $d->review, 'review_label' => self::REVIEWS[$d->review] ?? $d->review, 'reviewed_by' => $d->reviewer ? self::name($d->reviewer) : null,
                'reviewed_at' => $d->reviewed_at?->toIso8601String(), 'comment' => $d->comment,
            ])->values()->all(),
            // .93: el ganador que se itera, para compararlo al revisar y al entregar
            'iteration' => self::iteration($b),
            // .94: la referencia que sugirió el líder al asignar (de la que conviene partir)
            'suggested_reference' => $b->suggested_reference_id ? (ReferenceLibrary::brief([$b->suggested_reference_id])[$b->suggested_reference_id] ?? null) : null,
            'can' => array_combine(EditorRules::ACTIONS, array_map(fn ($a) => EditorRules::can($a, $who, $state), EditorRules::ACTIONS)),
            // La entrega del líder espera al dueño: se lo dice en vez de esconder el botón sin explicar
            'review_by_owner' => $b->status === 'review' && $pending !== null && $who['role'] === EditorRules::LEADER && (int) $pending->editor_id === $who['id'],
        ];
    }

    /**
     * `.93` (dueño: "que se vea la hipótesis que se quiere probar y lo que se le pidió al editor"): la hipótesis del
     * encargo la ve solo el líder, que revisa (sin precios). Los editores de video siguen sin verla (`.63`).
     *
     * @param array{id: int, role: ?string} $who
     * @return array{hypothesis?: ?string}
     */
    public static function hypothesisFor(array $who, ?string $hypothesis): array
    {
        return $who['role'] === EditorRules::LEADER ? ['hypothesis' => QueueRules::withoutPrices($hypothesis)] : [];
    }

    /**
     * `.93`: el ganador del que sale una iteración, para ponerlo al lado de lo entregado: su video si está en el sistema
     * (el creativo C0457 es la pieza 457), y si no, su miniatura, sus textos y lo que dice su video. Solo mientras el
     * encargo se hace o se revisa (después no hace falta y no se gasta en buscarlo). `.104` (dueño, 2026-11-05: "con el
     * nombre del anuncio y el ángulo es suficiente… lo busque en nuestro drive antiguo"): si su video no está en el
     * sistema, video_note dice su nombre completo y su ángulo para buscarlo ahí. Nada se pide a Meta.
     *
     * @return array{code: ?string, angle: ?string, file: ?array, video_note: ?string, thumbnail: ?string, texts: list<string>, script: ?array}|null
     */
    public static function iteration(CreativeBatch $b): ?array
    {
        if (!$b->reference_creative_id || !in_array($b->status, ['approved', 'production', 'review'], true)) {
            return null;
        }
        $creative = MetaCreative::find($b->reference_creative_id, ['id', 'label', 'creative_code', 'tracking_id', 'angle_code']);
        if (!$creative) {
            return null;
        }
        $fileId = preg_match('/^C(\d+)$/i', (string) $creative->creative_code, $m) ? CreativeBatchPiece::where('number', (int) $m[1])->value('file_id') : null;
        $file = $fileId ? (StoredFile::payloads([$fileId])[$fileId] ?? null) : null;
        $name = self::referenceName($creative);
        $angle = $creative->angle_code ?: $b->angle_code;
        $ref = self::references([$creative->id])[$creative->id] ?? [];
        $script = \App\Services\Meta\Videos\Scripts::forCreatives([$creative->id])[$creative->id] ?? null;

        return [
            // .103: C0457 si es del sistema; si no, el nombre del anuncio (el código solo, "CRE002", no dice cuál es)
            'code' => preg_match('/^C\d+$/i', (string) $creative->creative_code) ? $creative->creative_code : $name,
            'angle' => $angle,
            'file' => $file,
            'video_note' => $file ? null : 'Su video no está en el sistema: búscalo en el Drive antiguo como «' . ($name ?? 'el ganador') . '»'
                . ($angle ? " (ángulo {$angle})" : '') . '.',
            'thumbnail' => $ref['thumbnail'] ?? null,
            'texts' => QueueRules::withoutPricesIn($ref['texts'] ?? []),
            'script' => QueueRules::withoutPricesIn($script),
        ];
    }

    /** El estado de cada encargo para contar lo pendiente (EditorRules::pending). */
    public static function states(Collection $batches): array
    {
        return $batches->map(fn (CreativeBatch $b) => ['status' => $b->status, 'editor_id' => $b->editor_id,
            'pending_by' => $b->deliveries->firstWhere('review', 'pending')?->editor_id])->all();
    }

    /** En el orden de la cola: por estado, después la posición. */
    public static function sorted(Collection $batches): Collection
    {
        return $batches->sortBy(fn ($b) => [array_search($b->status, array_keys(QueueRules::STATUSES), true), $b->position ?? PHP_INT_MAX, $b->id])->values();
    }

    /** La miniatura y hasta dos textos del creativo de referencia, del anuncio más nuevo que lo usa. */
    private static function references(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $ads = DB::table('meta_ads')->whereIn('meta_creative_ref_id', $ids)->orderByDesc('created_time')
            ->get(['meta_creative_ref_id', 'thumbnail_url', 'image_url', 'ad_title', 'ad_body']);
        foreach ($ads->groupBy('meta_creative_ref_id') as $id => $rows) {
            $pic = $rows->first(fn ($a) => $a->image_url || $a->thumbnail_url);
            $out[$id] = [
                'thumbnail' => $pic ? ($pic->image_url ?: $pic->thumbnail_url) : null,
                'texts' => $rows->map(fn ($a) => implode(' — ', array_filter([trim((string) $a->ad_title), mb_strimwidth(trim((string) $a->ad_body), 0, 400, '…')])))
                    ->filter()->unique()->take(2)->values()->all(),
            ];
        }

        return $out;
    }

    /** Si no puede, por qué: no es suyo (403) o el encargo está en otro estado (422). */
    private function authorize(string $action, User $actor, CreativeBatch $batch, ?int $pendingBy = null): void
    {
        $who = self::who($actor);
        $state = ['status' => $batch->status, 'editor_id' => $batch->editor_id, 'pending_by' => $pendingBy];
        if (EditorRules::can($action, $who, $state)) {
            return;
        }
        $deny = fn (string $message) => new HttpResponseException(response()->json(['status' => false, 'message' => $message], 403));
        if (in_array($action, ['assign', 'review'], true) && !in_array($who['role'], [EditorRules::ADMIN, EditorRules::LEADER], true)) {
            throw $deny('Eso lo hace el editor líder.');
        }
        if (!EditorRules::canSee($who, $state) || in_array($action, ['start', 'deliver'], true) && (int) $batch->editor_id !== $who['id']) {
            throw $deny('Este encargo no está asignado a ti.');
        }
        if ($action === 'review' && $pendingBy !== null && $pendingBy === $who['id']) {
            throw $deny(EditorRules::ownReviewMessage());
        }
        $verbs = ['assign' => 'asignar', 'start' => 'empezar', 'deliver' => 'entregar', 'review' => 'revisar'];
        throw ValidationException::withMessages(['status' => 'Este encargo está "' . (QueueRules::STATUSES[$batch->status] ?? $batch->status)
            . '": ahora no se puede ' . $verbs[$action] . '.']);
    }

    private static function name(User $u): string
    {
        return trim($u->names . ' ' . ($u->surnames ?? ''));
    }
}
