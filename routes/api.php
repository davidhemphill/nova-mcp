<?php

use Hemp\NovaMcp\Http\Controllers\CatalogController;
use Hemp\NovaMcp\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tool API Routes
|--------------------------------------------------------------------------
|
| Backs the tool's Nova screen. These routes are protected by the tool's
| "Authorize" middleware, the same as the Inertia routes.
|
*/

Route::get('/catalog', CatalogController::class)->name('nova-mcp.catalog');

Route::get('/tokens', [TokenController::class, 'index'])->name('nova-mcp.tokens.index');
Route::post('/tokens', [TokenController::class, 'store'])->name('nova-mcp.tokens.store');
Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('nova-mcp.tokens.destroy');
