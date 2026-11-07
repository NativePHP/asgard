<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\RenderController;

// Deliberately outside the `web` group: no session, no CSRF, no cookies.
Route::match(['QUERY', 'POST'], trim(config('asgard.path', '_asgard'), '/').'/render', RenderController::class)
    ->middleware(config('asgard.middleware', []))
    ->name('asgard.render');
