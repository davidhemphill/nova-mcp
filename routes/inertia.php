<?php

use Illuminate\Support\Facades\Route;
use Laravel\Nova\Http\Requests\NovaRequest;

/*
|--------------------------------------------------------------------------
| Tool Routes
|--------------------------------------------------------------------------
|
| Inertia routes for the tool's Nova screen.
|
*/

Route::get('/', fn (NovaRequest $request) => inertia('NovaMcp'));
