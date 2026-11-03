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
 */
class ReferenceLibrary
{
    public const TYPES = ['ugc' => 'UGC', 'testimonial' => 'Testimonio', 'demo' => 'Demostración', 'before_after' => 'Antes y después',
        'animated' => 'Animado', 'other' => 'Otro'];

    /** De dónde salió una pieza. */
    public const SOURCES = ['reference' => 'Una referencia', 'own' => 'Idea propia', 'iteration' => 'Iteración de un ganador'];

    public const WHY_MAX = 500;

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
    public static function list(array $who, ?int $productId = null, ?string $type = null): array
    {
        $refs = CreativeReference::with(['file', 'product:id,title,showable_name', 'uploader:id,names,surnames'])->whereNull('archived_at')
            ->when($productId, fn ($q) => $q->where(fn ($w) => $w->where('product_id', $productId)->orWhereNull('product_id')))
            ->when($type && isset(self::TYPES[$type]), fn ($q) => $q->where('type', $type))
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

            return [
                'id' => $r->id, 'code' => $r->code(), 'link' => $r->link, 'type' => $r->type, 'type_label' => self::TYPES[$r->type] ?? $r->type,
                'why' => $r->why, 'product_id' => $r->product_id, 'product' => $r->product ? ($r->product->showable_name ?: $r->product->title) : null,
                'angle_code' => $r->angle_code, 'angle_name' => $angle['name'] ?? null,
                'file' => $r->file?->status === 'ready' ? $r->file->payload() : null,
                'uploaded_by' => $r->uploader ? trim($r->uploader->names . ' ' . ($r->uploader->surnames ?? '')) : null,
                'created_at' => $r->created_at?->toIso8601String(), 'uses' => (int) ($s->uses ?? 0),
                'can_archive' => self::seesResults($who),
            ] + ($results ? ['results' => ['won' => (int) ($s->won ?? 0), 'lost' => (int) ($s->lost ?? 0), 'tie' => (int) ($s->tie ?? 0)]] : []);
        })->values()->all();
    }

    /**
     * Guarda una referencia nueva con su video ya subido (Uploads, clase "reference"). Si el link ya está, avisa cuál es.
     *
     * @param array{file_id: int, link: string, type: string, why: string, product_id?: ?int, angle_code?: ?string} $data
     */
    public function create(array $data, User $actor): CreativeReference
    {
        $file = StoredFile::find($data['file_id']);
        $errors = [];
        if (!$file || $file->kind !== 'reference' || $file->status !== 'ready' || (int) $file->uploaded_by !== (int) $actor->id) {
            $errors['file'] = 'Sube el video de la referencia.';
        }
        if (!self::validLink($data['link'] ?? null)) {
            $errors['link'] = 'Pega el link de donde salió (empieza con https://).';
        }
        if (!isset(self::TYPES[$data['type'] ?? ''])) {
            $errors['type'] = 'Elige el tipo de video.';
        }
        $why = trim((string) ($data['why'] ?? ''));
        if ($why === '') {
            $errors['why'] = 'Escribe en una frase por qué la elegiste.';
        }
        $productId = !empty($data['product_id']) ? (int) $data['product_id'] : null;
        if ($productId && !Product::whereKey($productId)->exists()) {
            $errors['product_id'] = 'Ese producto no existe.';
        }
        $angle = $productId && !empty($data['angle_code']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['angle_code'])) : null;
        if ($errors === [] && ($same = CreativeReference::whereNull('archived_at')->where('link_key', self::linkKey($data['link']))->first())) {
            $errors['link'] = "Esa referencia ya está en la biblioteca: {$same->code()}. Úsala desde ahí.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $actor, $file, $why, $productId, $angle) {
            $number = (int) CreativeReference::lockForUpdate()->max('number') + 1;
            $ref = CreativeReference::create([
                'number' => $number, 'link' => trim($data['link']), 'link_key' => self::linkKey($data['link']), 'type' => $data['type'],
                'why' => mb_substr($why, 0, self::WHY_MAX), 'product_id' => $productId, 'angle_code' => $angle ?: null,
                'file_id' => $file->id, 'uploaded_by' => $actor->id,
            ]);
            // El video queda con el nombre de la referencia ("REF0003.mp4")
            $file->forceFill(['name' => $ref->code() . '.' . pathinfo($file->name, PATHINFO_EXTENSION), 'product_id' => $productId])->save();

            return $ref;
        });
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
                'link' => $r->link, 'file' => $r->file?->status === 'ready' ? $r->file->payload() : null]])->all();
    }
}
