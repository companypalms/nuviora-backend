<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\CreativeBatchPiece;
use App\Models\CreativeReference;
use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Meta\Products\TestingPhase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `.92` (dueño, 2026-11-03): la biblioteca de referencias. Decisiones del dueño:
 * - las buscan y suben los editores, con el video y el link de donde salió (de cualquier lado: Meta, TikTok, Instagram…),
 *   para un producto y su ángulo o para cualquier producto, el tipo de video y por qué la eligieron;
 * - entran directo; el líder o el dueño las pueden quitar (las piezas que la usaron la siguen mostrando);
 * - en cada pieza, al entregar, el editor dice de dónde salió (una referencia, idea propia o la iteración de un ganador);
 * - todos ven cuántas veces se usó cada una y en qué creativos; si ganaron o perdieron, solo el líder y el dueño;
 * - la referencia no va en el nombre del anuncio (sigue "C0457 POSP"); la IA no las toca.
 * `.97` (dueño, 2026-11-04): el producto y el ángulo son obligatorios (con "Otro ángulo" y una frase de cuál es; ya no
 * hay "cualquier producto", las viejas se quedan como están); "Subir varias" de una vez; y pueden ser videos o imágenes.
 */
class ReferenceLibrary
{
    public const TYPES = ['ugc' => 'UGC', 'testimonial' => 'Testimonio', 'demo' => 'Demostración', 'before_after' => 'Antes y después',
        'animated' => 'Animado', 'other' => 'Otro'];

    /** De dónde salió una pieza. */
    public const SOURCES = ['reference' => 'Una referencia', 'own' => 'Idea propia', 'iteration' => 'Iteración de un ganador'];

    public const WHY_MAX = 500;

    /** `.97`: "Subir varias", hasta 20 a la vez. */
    public const BULK_MAX = 20;

    /** `.97`: el ángulo que no va con ninguno de la ficha, con una frase de cuál es. */
    public const OTHER_ANGLE = 'other';

    public const ANGLE_OTHER_MAX = 160;

    /** ¿Ve si las piezas ganaron o perdieron? El líder y el dueño (decisión del dueño). */
    public static function seesResults(array $who): bool
    {
        return in_array($who['role'], [EditorRules::ADMIN, EditorRules::LEADER], true);
    }

    /** El link sin lo que no cambia de qué anuncio se trata (mayúsculas del sitio, la barra final, #…, utm_…). */
    public static function linkKey(string $link): string
    {
        $p = parse_url(trim($link)) ?: [];
        parse_str($p['query'] ?? '', $q);
        $q = array_filter($q, fn ($k) => !str_starts_with((string) $k, 'utm_') && $k !== 'fbclid', ARRAY_FILTER_USE_KEY);
        ksort($q);
        $host = preg_replace('/^(www\.|m\.)/', '', strtolower((string) ($p['host'] ?? '')));

        return hash('sha256', $host . rtrim((string) ($p['path'] ?? ''), '/') . ($q ? '?' . http_build_query($q) : ''));
    }

    public static function validLink(?string $link): bool
    {
        $link = trim((string) $link);

        return $link !== '' && mb_strlen($link) <= 1000 && filter_var($link, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $link) === 1;
    }

    /**
     * La biblioteca (sin las quitadas), la más nueva arriba; con un producto, las suyas y las que sirven para cualquiera.
     *
     * @param array{id: int, role: ?string} $who
     */
    public static function list(array $who, ?int $productId = null, ?string $type = null, ?string $format = null): array
    {
        $refs = CreativeReference::with(['file', 'product:id,title,showable_name', 'uploader:id,names,surnames'])->whereNull('archived_at')
            ->when($productId, fn ($q) => $q->where(fn ($w) => $w->where('product_id', $productId)->orWhereNull('product_id')))
            ->when($type && isset(self::TYPES[$type]), fn ($q) => $q->where('type', $type))
            // .97: solo los videos o solo las imágenes
            ->when(in_array($format, ['video', 'image'], true), fn ($q) => $q->whereHas('file', fn ($f) => $f->where('mime', 'like', "{$format}/%")))
            ->orderByDesc('number')->get();

        return self::present($refs, $who);
    }

    /** Las que más ganaron (para el líder y el dueño): las que tienen más piezas ganadoras, hasta 3. */
    public static function top(array $who): array
    {
        if (!self::seesResults($who)) {
            return [];
        }
        $all = self::list($who);
        $won = array_values(array_filter($all, fn ($r) => ($r['results']['won'] ?? 0) > 0));
        usort($won, fn ($a, $b) => [$b['results']['won'], $a['results']['lost']] <=> [$a['results']['won'], $b['results']['lost']]);

        return array_slice($won, 0, 3);
    }

    /** @param Collection<int, CreativeReference> $refs */
    public static function present(Collection $refs, array $who): array
    {
        $ids = $refs->pluck('id')->all();
        $stats = $ids === [] ? collect() : DB::table('creative_batch_pieces')->whereIn('source_reference_id', $ids)
            ->selectRaw('source_reference_id as id, count(*) as uses, sum(case when result = ? then 1 else 0 end) as won, '
                . 'sum(case when result = ? then 1 else 0 end) as lost, sum(case when result = ? then 1 else 0 end) as tie', ['Ganó', 'Perdió', 'Empate'])
            ->groupBy('source_reference_id')->get()->keyBy('id');
        $angles = TestingPhase::anglesMany($refs->pluck('product_id')->filter()->unique()->values()->all());
        $results = self::seesResults($who);

        return $refs->map(function (CreativeReference $r) use ($stats, $angles, $results, $who) {
            $s = $stats->get($r->id);
            $angle = collect($angles[$r->product_id] ?? [])->firstWhere('code', $r->angle_code);

            $file = $r->file?->status === 'ready' ? $r->file->payload() : null;

            return [
                'id' => $r->id, 'code' => $r->code(), 'link' => $r->link ?: null, 'type' => $r->type,
                'type_label' => $r->type ? (self::TYPES[$r->type] ?? $r->type) : null,
                'why' => $r->why ?: null, 'product_id' => $r->product_id, 'product' => $r->product ? ($r->product->showable_name ?: $r->product->title) : null,
                'angle_code' => $r->angle_code, 'angle_name' => $angle['name'] ?? null, 'angle_other' => $r->angle_other, // .97
                'file' => $file, 'format' => in_array($file['type'] ?? null, ['video', 'image'], true) ? $file['type'] : null, // .97: video o imagen
                'uploaded_by' => $r->uploader ? trim($r->uploader->names . ' ' . ($r->uploader->surnames ?? '')) : null,
                'created_at' => $r->created_at?->toIso8601String(), 'uses' => (int) ($s->uses ?? 0),
                'can_archive' => self::seesResults($who),
            ] + ($results ? ['results' => ['won' => (int) ($s->won ?? 0), 'lost' => (int) ($s->lost ?? 0), 'tie' => (int) ($s->tie ?? 0)]] : []);
        })->values()->all();
    }

    /**
     * Guarda una referencia nueva con su video o su imagen ya subido (Uploads, clase "reference"). Si el link ya está,
     * avisa cuál es.
     *
     * @param array{file_id: int, link: string, type: string, why: string, product_id?: ?int, angle_code?: ?string, angle_other?: ?string} $data
     */
    public function create(array $data, User $actor): CreativeReference
    {
        $errors = [];
        [$productId, $angle, $angleOther] = self::target($data, $errors);
        $file = StoredFile::find($data['file_id']);
        if ($problem = self::fileProblem($file, $actor)) {
            $errors['file'] = $problem;
        }
        if (!self::validLink($data['link'] ?? null)) {
            $errors['link'] = 'Pega el link de donde salió (empieza con https://).';
        }
        if (!isset(self::TYPES[$data['type'] ?? ''])) {
            $errors['type'] = 'Elige el tipo.';
        }
        $why = trim((string) ($data['why'] ?? ''));
        if ($why === '') {
            $errors['why'] = 'Escribe en una frase por qué la elegiste.';
        }
        if ($errors === [] && ($same = CreativeReference::whereNull('archived_at')->where('link_key', self::linkKey($data['link']))->first())) {
            $errors['link'] = "Esa referencia ya está en la biblioteca: {$same->code()}. Úsala desde ahí.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $actor, $file, $why, $productId, $angle, $angleOther) {
            $ref = CreativeReference::create([
                'number' => (int) CreativeReference::lockForUpdate()->max('number') + 1, 'link' => trim($data['link']),
                'link_key' => self::linkKey($data['link']), 'type' => $data['type'], 'why' => mb_substr($why, 0, self::WHY_MAX),
                'product_id' => $productId, 'angle_code' => $angle, 'angle_other' => $angleOther, 'file_id' => $file->id, 'uploaded_by' => $actor->id,
            ]);
            self::nameFile($ref, $file);

            return $ref;
        });
    }

    /**
     * `.97` "Subir varias" (dueño: "que se elija el producto, el ángulo, y se puedan agregar varias referencias de ese
     * producto y ángulo, las cuales posteriormente el sistema nombra de manera automática"): de 1 a 20 archivos ya
     * subidos, cada uno con su link opcional; el tipo y el "por qué", opcionales y para todas. Se guardan todas o
     * ninguna; los errores van por fila ("items.3.link").
     *
     * @param array{product_id?: ?int, angle_code?: ?string, angle_other?: ?string, type?: ?string, why?: ?string} $common
     * @param list<array{file_id: int, link?: ?string}> $items
     * @return list<CreativeReference>
     */
    public function createMany(array $common, array $items, User $actor): array
    {
        $errors = [];
        [$productId, $angle, $angleOther] = self::target($common, $errors);
        $type = trim((string) ($common['type'] ?? '')) ?: null;
        if ($type !== null && !isset(self::TYPES[$type])) {
            $errors['type'] = 'Ese tipo no existe.';
        }
        $why = trim((string) ($common['why'] ?? ''));
        $items = array_values($items);
        if ($items === [] || count($items) > self::BULK_MAX) {
            $errors['items'] = $items === [] ? 'Agrega al menos un archivo.' : 'Puedes subir hasta ' . self::BULK_MAX . ' referencias a la vez.';
        }
        $files = StoredFile::whereIn('id', array_map(fn ($i) => (int) ($i['file_id'] ?? 0), $items) ?: [0])->get()->keyBy('id');
        $rows = [];
        $keys = [];
        $fileIds = [];
        foreach ($items as $n => $item) {
            $file = $files->get((int) ($item['file_id'] ?? 0));
            if ($problem = self::fileProblem($file, $actor)) {
                $errors["items.{$n}.file"] = $problem;
            } elseif (isset($fileIds[$file->id])) {
                $errors["items.{$n}.file"] = 'Ese archivo está dos veces: quita uno.';
            }
            $fileIds[$file?->id ?? 0] = true;
            $link = trim((string) ($item['link'] ?? ''));
            $key = null;
            if ($link !== '' && !self::validLink($link)) {
                $errors["items.{$n}.link"] = 'El link debe empezar con https://.';
            } elseif ($link !== '') {
                $key = self::linkKey($link);
                if (isset($keys[$key])) {
                    $errors["items.{$n}.link"] = 'Ese link está repetido en esta carga.';
                }
                $keys[$key] = true;
            }
            $rows[$n] = ['file' => $file, 'link' => $link !== '' ? $link : null, 'key' => $key];
        }
        $existing = $keys === [] ? collect() : CreativeReference::whereNull('archived_at')->whereIn('link_key', array_keys($keys))->get()->keyBy('link_key');
        foreach ($rows as $n => $r) {
            if ($r['key'] && !isset($errors["items.{$n}.link"]) && ($same = $existing->get($r['key']))) {
                $errors["items.{$n}.link"] = "Ya está en la biblioteca: {$same->code()}.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($rows, $actor, $type, $why, $productId, $angle, $angleOther) {
            $number = (int) CreativeReference::lockForUpdate()->max('number');
            $refs = [];
            foreach ($rows as $r) {
                $ref = CreativeReference::create([
                    'number' => ++$number, 'link' => $r['link'], 'link_key' => $r['key'], 'type' => $type,
                    'why' => $why !== '' ? mb_substr($why, 0, self::WHY_MAX) : null, 'product_id' => $productId, 'angle_code' => $angle,
                    'angle_other' => $angleOther, 'file_id' => $r['file']->id, 'uploaded_by' => $actor->id,
                ]);
                self::nameFile($ref, $r['file']);
                $refs[] = $ref;
            }

            return $refs;
        });
    }

    /**
     * `.97` (dueño: "se deba marcar la casilla del ángulo de manera obligatoria… pero que permanezca la opción de otro
     * ángulo"): el producto y el ángulo, los dos obligatorios. El ángulo es uno de la ficha del producto u "other", con
     * una frase de cuál es. Devuelve [producto, código del ángulo, otro ángulo] y anota los errores.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    private static function target(array $data, array &$errors): array
    {
        $productId = !empty($data['product_id']) ? (int) $data['product_id'] : null;
        if (!$productId || !Product::whereKey($productId)->exists()) {
            $errors['product_id'] = $productId ? 'Ese producto no existe.' : 'Elige el producto.';

            return [null, null, null];
        }
        $angle = trim((string) ($data['angle_code'] ?? ''));
        if ($angle === self::OTHER_ANGLE) {
            $other = trim((string) ($data['angle_other'] ?? ''));
            if ($other === '') {
                $errors['angle_other'] = 'Escribe en pocas palabras cuál es el otro ángulo.';
            }

            return [$productId, null, mb_substr($other, 0, self::ANGLE_OTHER_MAX) ?: null];
        }
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $angle));
        if ($code === '') {
            $errors['angle_code'] = 'Elige el ángulo (o «Otro ángulo» si no va con ninguno).';
        } elseif (!in_array($code, array_column(TestingPhase::anglesMany([$productId])[$productId] ?? [], 'code'), true)) {
            $errors['angle_code'] = 'Ese ángulo no es de este producto: elige uno de la lista o «Otro ángulo».';
        }

        return [$productId, $code !== '' ? $code : null, null];
    }

    /** Lo que tiene de malo el archivo subido (o null): que no lo subió esa persona, que no terminó o que ya es una referencia. */
    private static function fileProblem(?StoredFile $file, User $actor): ?string
    {
        if (!$file || $file->kind !== 'reference' || $file->status !== 'ready' || (int) $file->uploaded_by !== (int) $actor->id) {
            return 'Sube el video o la imagen de la referencia.';
        }
        if ($same = CreativeReference::where('file_id', $file->id)->first()) {
            return "Ese archivo ya está en la biblioteca: {$same->code()}.";
        }

        return null;
    }

    /** El archivo queda con el nombre de la referencia ("REF0003.mp4") y su producto. */
    private static function nameFile(CreativeReference $ref, StoredFile $file): void
    {
        $file->forceFill(['name' => $ref->code() . '.' . pathinfo($file->name, PATHINFO_EXTENSION), 'product_id' => $ref->product_id])->save();
    }

    /** Quitarla de la biblioteca (el líder o el dueño). Las piezas que la usaron la siguen mostrando. */
    public function archive(CreativeReference $ref, User $actor): void
    {
        if (!self::seesResults(EditorWork::who($actor))) {
            throw new HttpResponseException(response()->json(['status' => false, 'message' => 'Quitar una referencia lo hace el editor líder.'], 403));
        }
        $ref->forceFill(['archived_at' => now(), 'archived_by' => $actor->id])->save();
    }

    /**
     * En qué creativos se usó: el código y el nombre en Meta de cada pieza, su batch, el producto, quién lo hizo, en qué va,
     * su video y (el líder y el dueño) si ganó, perdió o empató.
     */
    public static function uses(CreativeReference $ref, array $who): array
    {
        $pieces = CreativeBatchPiece::with(['batch:id,number,product_id,angle_code,status,editor_id', 'batch.product:id,title,showable_name', 'batch.editor:id,names,surnames'])
            ->where('source_reference_id', $ref->id)->orderByDesc('id')->get();
        $files = StoredFile::payloads($pieces->pluck('file_id')->all());
        $results = self::seesResults($who);

        return $pieces->map(function (CreativeBatchPiece $p) use ($files, $results) {
            /** @var CreativeBatch|null $b */
            $b = $p->batch;
            $names = QueueRules::metaNames($b?->number, $b?->angle_code, [$p->number]);

            return [
                'piece_id' => $p->id, 'code' => $p->code(), 'ad_name' => $names['ads'][0] ?? $p->code(), 'batch' => $b?->code(),
                'product' => $b?->product ? ($b->product->showable_name ?: $b->product->title) : null,
                'editor' => $b?->editor ? trim($b->editor->names . ' ' . ($b->editor->surnames ?? '')) : null,
                'status' => $b?->status, 'status_label' => QueueRules::STATUSES[$b?->status ?? ''] ?? $b?->status, 'file' => $files[$p->file_id] ?? null,
            ] + ($results ? ['result' => $p->result] : []);
        })->values()->all();
    }

    /** Los productos para elegir al subir una referencia: los que tienen ficha o encargos, con sus ángulos. */
    public static function productOptions(): array
    {
        $ids = ProductProfile::pluck('product_id')->merge(CreativeBatch::where('status', '!=', 'discarded')->distinct()->pluck('product_id'))
            ->filter()->unique()->values()->all();
        $angles = TestingPhase::anglesMany($ids);

        return Product::whereIn('id', $ids ?: [0])->get(['id', 'title', 'showable_name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->showable_name ?: $p->title,
                'angles' => array_map(fn ($a) => ['code' => $a['code'], 'name' => $a['name']], $angles[$p->id] ?? [])])
            ->sortBy('name')->values()->all();
    }

    /** Los códigos y el video de unas referencias, por id (para mostrar de dónde salió cada pieza). */
    public static function brief(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : CreativeReference::with('file')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (CreativeReference $r) => [$r->id => ['id' => $r->id, 'code' => $r->code(), 'type_label' => self::TYPES[$r->type] ?? $r->type,
                'link' => $r->link, 'file' => $r->file?->status === 'ready' ? $r->file->payload() : null,
                // .93: por qué la eligieron, para tenerlo al comparar
                'why' => $r->why]])->all();
    }
}
