<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Order::observe(\App\Observers\OrderTrackingObserver::class);
        \App\Models\Order::observe(\App\Observers\OrderLogisticsObserver::class);
        // Módulo 3 de Fran: almacén de la orden, ubicación del cliente y ruta con su costo esperado
        \App\Models\Order::observe(\App\Observers\OrderDeliveryObserver::class);
        // Logística propia (Fran, 21/10): la orden entra al centro de su ciudad y su estado logístico la acompaña
        \App\Models\Order::observe(\App\Observers\LogisticsOrderObserver::class);
        // Módulo 4 de Fran: la fecha de entrega y la economía de cada orden (order_financials) al día
        \App\Services\Finance\FinanceTriggers::register();
        // Paso 6: una cuenta desactivada no entra con ninguna sesión vieja (además, al desactivarla se borran sus sesiones)
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $isValid && $token->tokenable?->deactivated_at === null);
        // .164: los topes del formulario de la tienda (público), por IP. Generosos: en Venezuela muchos teléfonos comparten la IP
        \Illuminate\Support\Facades\RateLimiter::for('tienda-ver', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('tienda-pedidos', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('m' . $r->ip()), \Illuminate\Cache\RateLimiting\Limit::perHour(20)->by('h' . $r->ip())]);
    }
}
