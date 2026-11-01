<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\Product;
use App\Models\ProductProfile;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Outcome;
use App\Services\Assistant\Actions\Proposal;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Meta\Products\ProductProfileRules;
use App\Services\Meta\Products\ProductProfiles;
use App\Services\Meta\Products\TestingPhase;
use App\Services\Meta\Products\TestingRules;
use Illuminate\Support\Facades\DB;

/**
 * `.64` (dueño, 2026-11-01): "toda la info del producto debo poder colocársela al asistente de IA, y que él rellene por
 * completo la ficha, y solo me dé la tarjeta para aprobar". Llena o cambia la ficha de un producto (lo mismo que guarda
 * Configuración › Meta Ads › Ficha del producto) con lo que el dueño le pasó: texto, PDF o fotos.
 * `.65`: también los ángulos de venta (del más fuerte al más débil) y la fase (En testeo / Iterar lo que funciona).
 */
class ProductProfileTool extends ChangeTool
{
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
                            'hipotesis' => $text('Por qué puede vender: a quién le habla, qué dolor o deseo toca y por qué compraría. Solo la ve el dueño.'),
                            'buyer' => $text('El nombre exacto del buyer persona de la ficha al que apunta, o null.'),
                        ],
                        'required' => array_keys(self::ANGLE_ARGS),
                        'additionalProperties' => false,
                    ],
                ],
                'fase' => ['type' => 'string', 'enum' => ['testeo', 'iterar', 'sin_cambio'],
                    'description' => 'testeo = un producto nuevo que se va a testear (3 batches: 1 por cada uno de los 3 ángulos principales); iterar = ya se sabe qué funciona; sin_cambio = la deja como está.'],
            ],
            'required' => ['producto', ...array_keys(self::ARGS), 'buyer_personas', 'quitar_buyer_personas', 'angulos', 'fase'],
            'additionalProperties' => false,
        ];
    }

    public function working(array $args): string
    {
        return 'Preparando la ficha del producto…';
    }

    public function propose(array $args, ActionContext $context): Proposal
    {
        $productId = $this->lookup->productId($args['producto'] ?? null) ?? throw new ToolProblem('Falta el producto: pregúntale al dueño de cuál es la ficha.');
        $product = Product::find($productId, ['id', 'title', 'showable_name']);
        $name = $product->showable_name ?: $product->title;
        $now = ProductProfiles::get($productId);
        $angles = array_map(fn ($a) => ProductProfileRules::normalizeAngle($a), TestingPhase::angles($productId));

        return self::proposal($productId, $name, $now['profile'], $now['personas'], $args, $angles,
            (string) (ProductProfile::where('product_id', $productId)->value('phase') ?? 'iterate'), TestingPhase::testedAngles($productId));
    }

    /**
     * La tarjeta, sin base de datos (para las pruebas).
     *
     * @param list<array> $beforeAngles ProductProfileRules::normalizeAngle, del más fuerte al más débil
     * @param list<string> $tested los ángulos que ya salieron en un testeo (una ronda nueva no los repite)
     */
    public static function proposal(int $productId, string $name, ?array $before, array $beforePersonas, array $args, array $beforeAngles = [],
        string $beforePhase = 'iterate', array $tested = []): Proposal
    {
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
        $lines[] = Proposal::line('Hipótesis de los ángulos (solo tú)', $hyp ? implode("\n", $hyp) : null);
        if ($phase !== $beforePhase) {
            $changed[] = 'la fase';
        }
        $lines[] = Proposal::line('Fase', TestingRules::PHASES[$phase], $phase !== $beforePhase ? TestingRules::PHASES[$beforePhase] : null);
        if ($phase === 'testing' && ($phase !== $beforePhase || $anglesChanged)) {
            $lines[] = Proposal::line('El testeo pide', ProductProfileRules::testingText(array_values(array_diff(array_column($angles, 'code'), $tested)))
                . ($tested !== [] ? ' (los ángulos que ya se probaron no se repiten)' : ''));
        }
        if ($changed === [] && $newPersonas === 0 && $m['removed'] === []) {
            throw new ToolProblem('Con eso la ficha de ' . $name . ' queda igual: no hay nada que cambiar.');
        }

        $warnings = [];
        if ($missing = ProductProfileRules::missing($m['profile'], $m['personas'])) {
            $warnings[] = 'La ficha queda incompleta: falta ' . self::join(array_map('mb_strtolower', $missing)) . '. Mientras falte, la IA no escribe encargos ni landings de ' . $name . '.';
        }
        if ($phase === 'testing' && $angles === []) {
            $warnings[] = 'En testeo, pero sin ángulos: el testeo no puede empezar hasta que la ficha tenga sus ángulos.';
        }
        $first = $before === null && $beforePersonas === [] && $beforeAngles === [];
        $what = array_merge($changed, $newPersonas ? [$newPersonas . ($newPersonas === 1 ? ' buyer persona nuevo' : ' buyer personas nuevos')] : [],
            $m['removed'] ? ['quitar ' . count($m['removed']) . ' buyer persona' . (count($m['removed']) === 1 ? '' : 's')] : []);

        return new Proposal(
            $first ? "Llenar la ficha de {$name}" : "Cambiar la ficha de {$name}",
            ($first ? "Llenar la ficha de {$name}" : "Cambiar la ficha de {$name}: ") . ($first ? '' : self::join($what)),
            Proposal::lines($lines),
            ['product_id' => $productId, 'profile' => $m['profile'], 'personas' => $m['personas'],
                'angles' => $anglesChanged ? $angles : null, 'phase' => $phase !== $beforePhase ? $phase : null],
            $warnings,
        );
    }

    public function execute(array $payload, ActionContext $context): Outcome
    {
        $pid = (int) $payload['product_id'];
        DB::transaction(function () use ($payload, $pid, $context) {
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

        return new Outcome('Ficha guardada.' . ($missing ? ' Todavía falta: ' . self::join(array_map('mb_strtolower', $missing)) . '.' : ' Está completa: la IA ya la usa en los encargos.')
            . ($testing ? ' El testeo empieza: en la próxima propuesta de la mañana (o con "Proponer ahora" en Cola y banca) se piden sus batches.' : ''),
            ['label' => 'Ver la ficha', 'to' => '/admin/integraciones?seccion=meta&tab=profile&producto=' . $pid]);
    }

    private static function join(array $parts): string
    {
        $parts = array_values($parts);

        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : ($parts[0] ?? '');
    }
}
