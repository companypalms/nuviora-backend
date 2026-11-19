<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
 * `.164`: lo público de nuestro formulario en la landing de la tienda (https://api.nuviora.cloud/tienda/...). Va fuera de
 * /api para manejar su propio CORS (solo los dominios de la tienda del dueño) y sin sesión ni cookies.
 */
Route::get('cargador.js', [StorefrontController::class, 'loader'])->middleware('throttle:tienda-ver');
Route::get('productos/{shopifyId}', [StorefrontController::class, 'config'])->whereNumber('shopifyId')->middleware('throttle:tienda-ver');
Route::post('pedidos', [StorefrontController::class, 'order'])->middleware('throttle:tienda-pedidos');
Route::post('eventos', [StorefrontController::class, 'events'])->middleware('throttle:tienda-ver');
