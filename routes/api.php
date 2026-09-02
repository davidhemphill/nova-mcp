<?php

use Illuminate\Support\Facades\Route;
use NovaAi\McpTools\Http\Controllers\CatalogController;
use NovaAi\McpTools\Http\Controllers\TokenController;

/*
|--------------------------------------------------------------------------
| Tool API Routes
|--------------------------------------------------------------------------
|
| Backs the tool's Nova screen. These routes are protected by the tool's
| "Authorize" middleware, the same as the Inertia routes.
|
*/

Route::get('/catalog', CatalogController::class)->name('mcp-tools.catalog');

Route::get('/tokens', [TokenController::class, 'index'])->name('mcp-tools.tokens.index');
Route::post('/tokens', [TokenController::class, 'store'])->name('mcp-tools.tokens.store');
Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('mcp-tools.tokens.destroy');
