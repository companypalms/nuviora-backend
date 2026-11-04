<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\AssistantMessage;
use App\Models\Product;
use App\Models\ProductProfile;
use App\Models\StoredFile;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Files\Attachments;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Files\FileStore;
use App\Services\Files\StoredFileRules;
use App\Services\Files\Uploads;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\ShopifyLink;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Products\TestingRules;
use Illuminate\Support\Facades\DB;

/**
 * `.64` (dueño, 2026-11-01): "toda la info del producto debo poder colocársela al asistente de IA, y que él rellene por
 * completo la ficha, y solo me dé la tarjeta para aprobar". Llena o cambia la ficha de un producto (lo mismo que guarda
 * Configuración › Meta Ads › Ficha del producto) con lo que el dueño le pasó: texto, PDF o fotos.
 * `.65`: también los ángulos de venta (del más fuerte al más débil) y la fase (En testeo / Iterar lo que funciona).
 * `.89` (dueño, 2026-11-03): así entra un producto nuevo al sistema ("Producto nuevo: Termafit" + su estrategia + el link
 * de Shopify): si no existe, se crea al confirmar, en testeo (sus batches le llegan solos al líder). Con el link, guarda su
 * número de Shopify para que los pedidos se unan a él aunque el nombre no sea igual; si un pedido ya lo creó con ese
 * número, se usa ese (nunca uno repetido).
 * `.90` (dueño, 2026-11-03): las fotos y los PDF que adjuntó en la última hora se guardan en el producto al confirmar
 * (fotos → las fotos del producto, que ven los editores; PDF → su documento de estrategia, que solo ve el dueño).
 */
class ProductProfileTool extends ChangeTool
{
    /** `.90`: cuánto hacia atrás se buscan los adjuntos de la conversación. */
    public const FILES_MINUTES = 60;

    /** Lo que recibe la IA → el campo de la ficha. */
    public const ARGS = [
        'que_es' => 'what_it_is', 'para_que_sirve' => 'benefits', 'como_funciona' => 'mechanism',
        'lo_que_no_es' => 'not_this', 'info_clave' => 'key_info', 'fotos' => 'photos_url',
    ];

    public const PERSONA_ARGS = ['nombre' => 'name', 'quien_es' => 'who', 'que_le_duele' => 'pain', 'que_quiere' => 'wants', 'que_lo_frena' => 'objections'];

    public const ANGLE_ARGS = ['codigo' => 'code', 'nombre' => 'name', 'hipotesis' => 'hypothesis', 'buyer' => 'persona'];

    private const PHASE_ARGS = ['testeo' => 'testing', 'iterar' => 'iterate'];

    public function __construct(private Lookup $lookup)
    {
    }

    public function name(): string
    {
        return 'proponer_ficha_producto';
    }

    public function description(): string
    {
        return 'Propone llenar o cambiar la ficha de un producto (qué es, para qué sirve, cómo funciona, lo que NO es y nunca se dice, información clave, link de las fotos, sus buyer personas, sus ángulos de venta del más fuerte al más débil y su fase: en testeo o iterar lo que funciona) con la información que te dio el dueño en el chat, en un PDF o en fotos. La IA que escribe los encargos para los editores y las landings lee esta ficha. El dueño ve una tarjeta con toda la ficha y la confirma. Un campo en null queda como está; para cambiar un campo escríbelo completo (ya con lo de antes que siga valiendo: usa ficha_producto para leerlo).';
    }

    public function parameters(): array
    {
        $text = fn (string $d) => ['type' => ['string', 'null'], 'description' => $d];

        return [
            'type' => 'object',
            'properties' => [
                'producto' => ['type' => 'string', 'description' => 'El nombre del producto.'],
                'que_es' => $text('Qué es, en 1 o 2 frases: "Chaleco térmico reductor de neopreno para hombre". null = no cambia.'),
                'para_que_sirve' => $text('Para qué sirve: los resultados y beneficios que promete, tal como los dio el dueño, aunque sean promesas fuertes o de salud (sin suavizarlas ni agregar advertencias). null = no cambia.'),
                'como_funciona' => $text('Cómo funciona (el mecanismo), en palabras sencillas. null = no cambia.'),
                'lo_que_no_es' => $text('Lo que NO es y nunca se dice, solo lo que dijo el dueño (para qué no sirve, lo que se confunde, lo prohibido). null = no cambia.'),
                'info_clave' => $text('Información clave: tallas, colores, cómo se usa, cuánto tiempo, garantía, lo que trae. null = no cambia.'),
                'fotos' => $text('El link de la carpeta de fotos (Drive), https://… null = no cambia.'),
                'buyer_personas' => [
                    'type' => 'array',
                    'description' => 'Los buyer personas que se agregan o cambian (se buscan por nombre). Lista vacía = ninguno cambia.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre' => ['type' => 'string', 'description' => 'Un nombre corto que lo describe: "Hombre con ginecomastia".'],
                            'quien_es' => $text('Quién es: edad, sexo, a qué se dedica, cómo vive. null = no cambia.'),
                            'que_le_duele' => $text('Qué le duele o le molesta, con sus palabras. null = no cambia.'),
                            'que_quiere' => $text('Qué quiere lograr. null = no cambia.'),
                            'que_lo_frena' => $text('Qué lo frena para comprar (dudas, miedos). null = no cambia.'),
                        ],
                        'required' => array_keys(self::PERSONA_ARGS),
                        'additionalProperties' => false,
                    ],
                ],
                'quitar_buyer_personas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Los nombres de los buyer personas que se quitan de la ficha. Lista vacía = ninguno.'],
                'angulos' => [
                    'type' => 'array',
                    'description' => 'La lista COMPLETA de ángulos de venta de la ficha, del más fuerte al más débil (el primero es el más fuerte; en el testeo se prueban los 3 primeros). Lista vacía = no cambian.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'codigo' => ['type' => 'string', 'description' => 'El código corto que va en los nombres de Meta, de 2 a 12 letras o números: "GINE". Si el ángulo ya existe, su mismo código.'],
                            'nombre' => ['type' => 'string', 'description' => 'El nombre del ángulo: "Ginecomastia".'],
                            'hipotesis' => $text('Por qué puede vender: a quién le habla, qué dolor o deseo toca y por qué compraría. Los editores la ven como "de qué trata" el ángulo (sin precios).'),
                            'buyer' => $text('El nombre exacto del buyer persona de la ficha al que apunta, o null.'),
                        ],
                        'required' => array_keys(self::ANGLE_ARGS),
                        'additionalProperties' => false,
                    ],
                ],
                'producto_nuevo' => ['type' => 'boolean', 'description' => 'true si el dueño dice que es un producto nuevo que todavía no está en el sistema: se crea al confirmar, en testeo. Si ya existe con ese nombre, se usa ese.'],
                'link_shopify' => $text('El link del producto en Shopify, si el dueño lo pasó (el del panel: admin.shopify.com/…/products/número, o el de la página en la tienda: …/products/nombre). Así los pedidos de ese producto se unen a él. null si no lo pasó.'),
                'fase' => ['type' => 'string', 'enum' => ['testeo', 'iterar', 'sin_cambio'],
                    'description' => 'testeo = un producto nuevo que se va a testear (3 batches: 1 por cada uno de los 3 ángulos principales); iterar = ya se sabe qué funciona; sin_cambio = la deja como está.'],
            ],
            'required' => ['producto', ...array_keys(self::ARGS), 'buyer_personas', 'quitar_buyer_personas', 'angulos', 'producto_nuevo', 'link_shopify', 'fase'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la ficha del producto…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        // .89: el link de Shopify da el número del producto en la tienda; con él se encuentra el producto aunque el nombre cambie
        $new = (bool) ($args['producto_nuevo'] ?? false);
        $files = self::attachments($context);
        $shopify = trim((string) ($args['link_shopify'] ?? '')) !== '' ? ShopifyLink::resolve($args['link_shopify']) : null;
        if ($shopify && $shopify['problem']) {
            throw new ToolProblem($shopify['problem']);
        }
        $productId = $shopify ? Product::where('product_id', $shopify['id'])->where('product_id', '>', 0)->orderBy('id')->value('id') : null;
        if ($productId === null) {
            try {
                $productId = $this->lookup->productId($args['producto'] ?? null);
            } catch (ToolProblem $e) {
                if (!$new || !str_starts_with($e->getMessage(), 'No encontré')) {
                    throw $e;
                }
            }
        }
        if ($productId === null) {
            $name = trim((string) ($args['producto'] ?? ''));
            if (!$new || $name === '') {
                throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál es la ficha.');
            }

            return self::proposal(0, $name, null, [], ['fase' => 'testeo'] + array_diff_key($args, ['fase' => 1]), [], 'iterate', [], ['create' => true, 'shopify' => $shopify], $files);
        }
        $product = Product::find($productId, ['id', 'title', 'showable_name', 'product_id']);
        $name = $product->showable_name ?: $product->title;
        $now = ProductProfiles::get($productId);
        $angles = array_map(fn ($a) => ProductProfileRules::normalizeAngle($a), TestingPhase::angles($productId));

        return self::proposal($productId, $name, $now['profile'], $now['personas'], $args, $angles,
            (string) (ProductProfile::where('product_id', $productId)->value('phase') ?? 'iterate'), TestingPhase::testedAngles($productId),
            ['create' => false, 'shopify' => $shopify && (int) $product->product_id !== $shopify['id'] ? $shopify : null,
                'campaigns' => TestingPhase::liveCampaigns($productId)], $files);
    }

    /**
     * `.90`: las fotos y los PDF que el dueño adjuntó en la última hora (con el almacén encendido), para guardarlos en el
     * producto al confirmar: una foto → foto del producto; un PDF → documento de estrategia.
     *
     * @return list<array{message_id: int, index: int, name: string, mime: string, kind: string}>
     */
    public static function attachments(ActionContext $context): array
    {
        if (!FileStore::on()) {
            return [];
        }
        $messages = AssistantMessage::where('user_id', $context->user->id)->where('role', 'user')->whereNotNull('attachments')
            ->where('created_at', '>=', $context->now->subMinutes(self::FILES_MINUTES))->orderBy('id')->get(['id', 'attachments']);
        $out = [];
        foreach ($messages as $m) {
            foreach ((array) $m->attachments as $i => $f) {
                $mime = strtolower((string) ($f['mime'] ?? ''));
                $kind = isset(StoredFileRules::IMAGES[$mime]) ? 'photo' : ($mime === 'application/pdf' ? 'strategy' : null);
                if ($kind !== null && !empty($f['path']) && empty($f['problem'])) {
                    $out[] = ['message_id' => (int) $m->id, 'index' => (int) $i, 'name' => (string) ($f['name'] ?? 'archivo'), 'mime' => $mime, 'kind' => $kind];
                }
            }
        }

        return array_slice($out, 0, 20);
    }

    /**
     * `.90`: guarda en el producto lo que se adjuntó (sin repetir lo que ya está: mismo nombre y tamaño).
     *
     * @param list<array{message_id: int, index: int, name: string, mime: string, kind: string}> $files
     * @return array{photos: int, strategy: int, problems: list<string>}
     */
    public static function saveFiles(array $files, int $productId, int $userId): array
    {
        $out = ['photos' => 0, 'strategy' => 0, 'problems' => []];
        $disk = \Illuminate\Support\Facades\Storage::disk(Attachments::DISK);
        foreach ($files as $f) {
            $att = ((array) AssistantMessage::find($f['message_id'])?->attachments)[$f['index']] ?? null;
            if (!$att || empty($att['path']) || !$disk->exists($att['path'])) {
                $out['problems'][] = "{$f['name']}: ya no está en el chat.";
                continue;
            }
            $path = $disk->path($att['path']);
            if (StoredFile::where('product_id', $productId)->where('kind', $f['kind'])->where('status', 'ready')->where('size', (int) filesize($path))
                ->where('name', $f['name'])->exists()) {
                continue;
            }
            try {
                app(Uploads::class)->fromLocal($f['kind'], $productId, $path, $f['name'], $f['mime'], $userId);
                $out[$f['kind'] === 'photo' ? 'photos' : 'strategy']++;
            } catch (\Throwable $e) {
                report($e);
                $out['problems'][] = "{$f['name']}: no se pudo guardar.";
            }
        }

        return $out;
    }

    /**
     * La tarjeta, sin base de datos (para las pruebas).
     *
     * @param list<array> $beforeAngles ProductProfileRules::normalizeAngle, del más fuerte al más débil
     * @param list<string> $tested los ángulos que ya salieron en un testeo (una ronda nueva no los repite)
     * @param list<array{name: string, kind: string}> $files `.90`: lo adjuntado que se guarda en el producto (self::attachments)
     */
    public static function proposal(int $productId, string $name, ?array $before, array $beforePersonas, array $args, array $beforeAngles = [],
        string $beforePhase = 'iterate', array $tested = [], array $link = [], array $files = []): Proposal
    {
        // .89: un producto nuevo (se crea al confirmar) y el enlace con Shopify
        $create = (bool) ($link['create'] ?? false);
        $shopify = $link['shopify'] ?? null;
        $changes = [];
        foreach (self::ARGS as $arg => $field) {
            $changes[$field] = $args[$arg] ?? null;
        }
        $personaChanges = array_map(function ($p) {
            $x = [];
            foreach (self::PERSONA_ARGS as $arg => $field) {
                $x[$field] = is_array($p) ? ($p[$arg] ?? null) : null;
            }

            return ProductProfileRules::normalizePersona($x);
        }, array_values((array) ($args['buyer_personas'] ?? [])));
        $m = ProductProfileRules::merge($before, $beforePersonas, $changes, $personaChanges, array_values((array) ($args['quitar_buyer_personas'] ?? [])));
        if ($m['unknown'] !== []) {
            throw new ToolProblem('En la ficha de ' . $name . ' no hay ningún buyer persona que se llame "' . implode('", "', $m['unknown']) . '". '
                . ($beforePersonas ? 'Los que hay: ' . implode(', ', array_column($beforePersonas, 'name')) . '.' : 'Todavía no tiene ninguno.'));
        }
        // .65: los ángulos (la lista completa, del más fuerte al más débil) y la fase
        $angleArgs = array_values((array) ($args['angulos'] ?? []));
        $angles = $angleArgs === [] ? $beforeAngles : array_map(function ($a) {
            $x = [];
            foreach (self::ANGLE_ARGS as $arg => $field) {
                $x[$field] = is_array($a) ? ($a[$arg] ?? null) : null;
            }

            return ProductProfileRules::normalizeAngle($x);
        }, $angleArgs);
        $phase = self::PHASE_ARGS[$args['fase'] ?? ''] ?? $beforePhase;
        if ($problems = array_merge(ProductProfileRules::problems($m['profile'], $m['personas']), ProductProfileRules::angleProblems($angles, $m['personas']))) {
            throw new ToolProblem(implode(' ', $problems));
        }

        $old = ProductProfileRules::normalize($before ?? []);
        $lines = [Proposal::line('Producto', $name)];
        $changed = [];
        if ($create) {
            $lines[] = Proposal::line('Producto nuevo', 'Se crea al confirmar y empieza su testeo: sus batches le llegan solos al editor líder.');
        }
        if ($shopify) {
            $changed[] = 'el enlace con Shopify';
            $lines[] = Proposal::line('Shopify', ($shopify['title'] ? '«' . $shopify['title'] . '» · ' : '') . "número {$shopify['id']}: sus pedidos se unen a este producto.");
        }
        foreach (ProductProfileRules::FIELDS as $k => $label) {
            $differs = $m['profile'][$k] !== $old[$k];
            if ($differs) {
                $changed[] = mb_strtolower($label);
            }
            $lines[] = Proposal::line($label, $m['profile'][$k], $differs ? $old[$k] : null);
        }
        $newPersonas = 0;
        foreach ($m['personas'] as $p) {
            $was = ProductProfileRules::findByName($beforePersonas, $p['name']);
            $text = ProductProfileRules::personaText($p);
            $wasText = $was ? ProductProfileRules::personaText($was) : null;
            if ($was === null) {
                $newPersonas++;
            } elseif ($wasText !== $text) {
                $changed[] = "el buyer persona {$p['name']}";
            }
            $lines[] = Proposal::line("Buyer persona: {$p['name']}", $text !== '' ? $text : '(sin detalles)', $was !== null && $wasText !== $text ? $wasText : null);
        }
        if ($m['removed'] !== []) {
            $lines[] = Proposal::line('Se quita de la ficha', implode(', ', array_column($m['removed'], 'name')));
        }
        $anglesChanged = $angles != $beforeAngles;
        if ($anglesChanged) {
            $changed[] = 'los ángulos';
        }
        $oldAngles = ProductProfileRules::anglesText($beforeAngles);
        $lines[] = Proposal::line('Ángulos (del más fuerte al más débil)', ProductProfileRules::anglesText($angles), $anglesChanged && $oldAngles !== '' ? $oldAngles : null);
        $hyp = array_filter(array_map(fn ($a) => $a['hypothesis'] ? "{$a['code']}: {$a['hypothesis']}" : null, $angles));
        // .90: el editor la ve como "de qué trata" el ángulo (sin precios); el orden de fuerza sigue siendo solo del dueño
        $lines[] = Proposal::line('De qué trata cada ángulo (lo ven los editores)', $hyp ? implode("\n", $hyp) : null);
        if ($phase !== $beforePhase) {
            $changed[] = 'la fase';
        }
        $lines[] = Proposal::line('Fase', TestingRules::PHASES[$phase], $phase !== $beforePhase ? TestingRules::PHASES[$beforePhase] : null);
        if ($phase === 'testing' && ($phase !== $beforePhase || $anglesChanged)) {
            $lines[] = Proposal::line('El testeo pide', ProductProfileRules::testingText(array_values(array_diff(array_column($angles, 'code'), $tested)))
                . ($tested !== [] ? ' (los ángulos que ya se probaron no se repiten)' : ''));
        }
        // .90: lo que se guarda en el producto (fotos para los editores; el PDF, solo para ti)
        $photos = array_column(array_filter($files, fn ($f) => $f['kind'] === 'photo'), 'name');
        $docs = array_column(array_filter($files, fn ($f) => $f['kind'] === 'strategy'), 'name');
        if ($files !== []) {
            $lines[] = Proposal::line('Se guarda en el producto', implode("\n", array_filter([
                $photos ? (count($photos) === 1 ? '1 foto' : count($photos) . ' fotos') . ' (las ven los editores): ' . implode(', ', $photos) : null,
                $docs ? 'Documento de estrategia (solo tú): ' . implode(', ', $docs) : null,
            ])));
        }
        if ($changed === [] && $newPersonas === 0 && $m['removed'] === [] && $files === []) {
            throw new ToolProblem('Con eso la ficha de ' . $name . ' queda igual: no hay nada que cambiar.');
        }

        $warnings = [];
        if ($missing = ProductProfileRules::missing($m['profile'], $m['personas'])) {
            $warnings[] = 'La ficha queda incompleta: falta ' . self::join(array_map('mb_strtolower', $missing)) . '. Mientras falte, la IA no escribe encargos ni landings de ' . $name . '.';
        }
        if ($phase === 'testing' && $angles === []) {
            $warnings[] = 'En testeo, pero sin ángulos: el testeo no puede empezar hasta que la ficha tenga sus ángulos.';
        }
        // .100: un producto que ya vende normalmente no va en testeo
        if ($phase === 'testing' && $phase !== $beforePhase && ($w = TestingRules::sellingWarning($name, (int) ($link['campaigns'] ?? 0)))) {
            $warnings[] = $w;
        }
        if ($create && !$shopify) {
            $warnings[] = 'Todavía sin el link de Shopify: pásamelo cuando el producto esté en tu tienda (antes de lanzar el testeo), para que sus pedidos se unan a él.';
        }
        $first = $before === null && $beforePersonas === [] && $beforeAngles === [];
        $what = array_merge($changed, $newPersonas ? [$newPersonas . ($newPersonas === 1 ? ' buyer persona nuevo' : ' buyer personas nuevos')] : [],
            $m['removed'] ? ['quitar ' . count($m['removed']) . ' buyer persona' . (count($m['removed']) === 1 ? '' : 's')] : [],
            $files ? ['guardar ' . count($files) . (count($files) === 1 ? ' archivo' : ' archivos')] : []);

        $title = $create ? "Crear {$name} y llenar su ficha" : ($first ? "Llenar la ficha de {$name}" : "Cambiar la ficha de {$name}");

        return new Proposal(
            $title,
            $title . ($first || $create ? '' : ': ' . self::join($what)),
            Proposal::lines($lines),
            ['product_id' => $productId, 'profile' => $m['profile'], 'personas' => $m['personas'],
                'angles' => $anglesChanged ? $angles : null, 'phase' => $phase !== $beforePhase ? $phase : null,
                // .89: el producto que se crea y el número de Shopify que se guarda
                'create' => $create ? ['name' => $name, 'title' => $shopify['title'] ?? $name, 'price' => $shopify['price'] ?? 0, 'shopify_id' => $shopify['id'] ?? 0] : null,
                'shopify' => !$create && $shopify ? ['id' => $shopify['id']] : null,
                'files' => $files], // .90
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $pid = (int) $payload['product_id'];
        $created = false;
        DB::transaction(function () use ($payload, &$pid, &$created, $context) {
            // .89: el producto nuevo (si un pedido ya lo creó con ese número de Shopify, se usa ese: nunca uno repetido)
            if ($c = $payload['create'] ?? null) {
                $existing = (int) $c['shopify_id'] > 0 ? Product::where('product_id', (int) $c['shopify_id'])->orderBy('id')->value('id') : null;
                $pid = (int) ($existing ?? Product::create(['product_id' => (int) $c['shopify_id'], 'title' => $c['title'], 'name' => $c['name'],
                    'showable_name' => $c['name'], 'price' => (float) $c['price']])->id);
                $created = $existing === null;
            }
            if ($s = $payload['shopify'] ?? null) {
                Product::whereKey($pid)->update(['product_id' => (int) $s['id']]);
            }
            ProductProfiles::save($pid, $payload['profile'], $payload['personas'], $context->user->id, 'assistant');
            if (($payload['angles'] ?? null) !== null) {
                TestingPhase::saveAngles($pid, $payload['angles'], $context->user->id);
            }
            if (($payload['phase'] ?? null) !== null) {
                TestingPhase::setPhase($pid, $payload['phase']);
            }
        });
        $f = ProductProfiles::get($pid);
        $missing = ProductProfileRules::missing($f['profile'], $f['personas']);
        $testing = ($payload['phase'] ?? null) === 'testing' && $missing === [];
        // .90: las fotos y el documento de estrategia que adjuntó
        $saved = self::saveFiles($payload['files'] ?? [], $pid, (int) $context->user->id);
        $savedText = self::join(array_filter([
            $saved['photos'] ? ($saved['photos'] === 1 ? '1 foto' : "{$saved['photos']} fotos") : null,
            $saved['strategy'] ? ($saved['strategy'] === 1 ? 'el documento de estrategia' : "{$saved['strategy']} documentos de estrategia") : null,
        ]));

        return new Outcome(($created ? 'Producto creado y ficha guardada.' : 'Ficha guardada.')
            . ($savedText !== '' ? " Guardé {$savedText} en el producto." : '')
            . ($saved['problems'] ? ' No pude guardar: ' . implode(' ', $saved['problems']) : '')
            . ($missing ? ' Todavía falta: ' . self::join(array_map('mb_strtolower', $missing)) . '.' : ' Está completa: la IA ya la usa en los encargos.')
            . ($testing ? ' El testeo empieza: sus batches le llegan ya al editor líder.' : '')
            . (($payload['create'] ?? null) && (int) $payload['create']['shopify_id'] === 0 ? ' Cuando esté en tu tienda, pásame el link de Shopify.' : ''),
            ['label' => 'Ver la ficha', 'to' => '/admin/integraciones?seccion=meta&tab=profile&producto=' . $pid]);
    }

    private static function join(array $parts): string
    {
        $parts = array_values($parts);

        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : ($parts[0] ?? '');
    }
}
