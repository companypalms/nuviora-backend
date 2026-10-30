<?php

namespace App\Console\Commands;

use App\Services\Meta\Decisions\MetaDecisions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lo que dicen las reglas del dueño (Etapa 5, paso 2), en texto, para compararlo con su propia lectura. Solo lee: no
 * cambia nada en la base ni en Meta.
 */
class MetaDecisionsReport extends Command
{
    protected $signature = 'meta:decisions {--product= : ID del producto (sin esto, los que tienen campañas activas)} {--all : también lo inactivo}';

    protected $description = 'Muestra los veredictos de las reglas del dueño por producto, ciudad, batch, anuncio y creativo (solo lectura)';

    public function handle(MetaDecisions $decisions): int
    {
        $ids = $this->option('product') ? [(int) $this->option('product')]
            : DB::table('meta_campaigns')->whereNotNull('product_id')->where('effective_status', 'ACTIVE')->whereNull('missing_since')
                ->distinct()->pluck('product_id')->all();
        $all = (bool) $this->option('all');
        $names = fn (string $table, array $ids) => DB::table($table)->whereIn('meta_id', $ids ?: ['-'])->get(['meta_id', 'name', 'effective_status', 'missing_since'])->keyBy('meta_id');

        foreach ($decisions->compute($ids) as $p) {
            $d = $p['dates'];
            $this->line('');
            $this->info("== {$p['product']['name']} (modo {$p['product']['mode']}) · 3 días: {$d['3d'][0]} a {$d['3d'][1]}");
            if ($p['tracking']) {
                $this->line("   Tracking 3 días: Meta {$p['tracking']['meta_purchases']} compras / {$p['tracking']['orders']} pedidos reales"
                    . ($p['tracking']['ratio'] !== null ? " ({$p['tracking']['ratio']} %)" : ''));
            }
            $campaigns = $names('meta_campaigns', array_map('strval', array_keys($p['campaigns'])));
            foreach ($p['campaigns'] as $id => $c) {
                $row = $campaigns->get($id);
                if (!$all && ($row?->effective_status !== 'ACTIVE' || $row?->missing_since)) {
                    continue;
                }
                $this->line("-- {$c['city']} · {$row?->name}" . ($c['frequency'] !== null ? ' · frecuencia 3d ' . round($c['frequency'], 2) : ''));
                foreach ($c['notes'] as $n) {
                    $this->line("   ! {$n['label']}: {$n['text']}");
                }
            }
            $this->line('-- Batches');
            $sets = $names('meta_adsets', array_map('strval', array_keys($p['adsets'])));
            foreach ($p['adsets'] as $id => $v) {
                $this->verdictLine($sets->get($id), $v, $all);
            }
            $this->line('-- Anuncios');
            $ads = $names('meta_ads', array_map('strval', array_keys($p['ads'])));
            foreach ($p['ads'] as $id => $v) {
                $this->verdictLine($ads->get($id), $v, $all);
            }
            $this->line('-- Creativos');
            foreach ($p['creatives'] as $cr) {
                if (!$all && !$cr['active']) {
                    continue;
                }
                $v = $cr['verdict'];
                $this->line("   {$cr['label']}: {$v['label']}" . ($cr['funnel'] ? " · {$cr['funnel']['label']}" : '') . " · {$v['reading']}" . ($v['proposal'] ? " → {$v['proposal']}" : ''));
                foreach ($cr['cities'] as $city) {
                    $this->line("      {$city['city']}: {$city['label']} · {$city['data']}");
                }
                foreach ($cr['video']['diagnosis'] ?? [] as $dx) {
                    $this->line("      video ({$cr['video']['window']}): {$dx}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function verdictLine(?object $row, array $v, bool $all): void
    {
        if (!$row || (!$all && ($row->effective_status !== 'ACTIVE' || $row->missing_since))) {
            return;
        }
        $this->line("   {$row->name}: {$v['label']} · {$v['data']}" . ($v['proposal'] ? " → {$v['proposal']}" : ''));
    }
}
