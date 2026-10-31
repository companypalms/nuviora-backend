<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cuántos pedidos nuevos traen de dónde vinieron (`.53`), para medir al día siguiente de publicar: con landing, con los
 * parámetros del anuncio, con el anuncio encontrado y con su landing. Y cuántos anuncios activos tienen ya los
 * parámetros de URL. Solo lee.
 */
class OrderSourcesReport extends Command
{
    protected $signature = 'orders:sources {--days=1 : los pedidos creados en estos últimos días}';

    protected $description = 'Muestra cuántos pedidos nuevos traen su origen (landing, parámetros del anuncio, anuncio) y cuántos anuncios activos tienen los parámetros (solo lectura)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $orders = DB::table('orders')->where('created_at', '>=', $since)->count();
        $s = fn () => DB::table('order_sources as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('o.created_at', '>=', $since);
        $pct = fn (int $n) => $orders > 0 ? ' (' . round($n / $orders * 100) . ' %)' : '';
        $line = fn (string $label, int $n) => $this->line(str_pad($label, 44) . $n . $pct($n));

        $this->info("ORIGEN DE LOS PEDIDOS · los creados desde el " . $since->format('d/m/Y H:i') . " ({$orders} pedidos)");
        $line('Con su origen guardado', $s()->count());
        $line('Con la página donde entró (landing)', $s()->whereNotNull('s.landing_url')->count());
        $line('Con parámetros utm', $s()->where(fn ($q) => $q->whereNotNull('s.utm_source')->orWhereNotNull('s.utm_content'))->count());
        $line('Con clic de Facebook/Instagram (fbclid)', $s()->where('s.has_fbclid', true)->count());
        $line('Que dicen su anuncio (id)', $s()->whereNotNull('s.ad_meta_id')->count());
        $line('Con el anuncio encontrado en el sistema', $s()->whereNotNull('s.meta_ad_id')->count());
        $line('Con su landing del sistema', $s()->whereNotNull('s.landing_id')->count());

        $by = fn (string $col) => $s()->groupBy("s.{$col}")->selectRaw("COALESCE(s.{$col}, '(nada)') v, COUNT(*) n")->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => "{$r->v}: {$r->n}")->implode(' | ');
        $this->line('De dónde (Shopify): ' . ($by('source_name') ?: '—'));
        $this->line('utm_source: ' . ($by('utm_source') ?: '—'));
        foreach ($s()->orderByDesc('o.id')->limit(3)->get(['o.name', 's.landing_url', 's.utm_content', 's.source_name']) as $e) {
            $this->line("  Ej.: {$e->name} · " . ($e->landing_url ?? 'sin landing') . ' · utm_content ' . ($e->utm_content ?? '—') . ' · ' . ($e->source_name ?? '—'));
        }

        $active = fn () => DB::table('meta_ads')->where('effective_status', 'ACTIVE')->whereNull('missing_since');
        $withTags = $active()->where('url_tags', 'like', '%{{ad.id}}%')->count();
        $this->line('');
        $this->line("Anuncios activos con los parámetros de URL ({{ad.id}}): {$withTags} de " . $active()->count());

        return self::SUCCESS;
    }
}
