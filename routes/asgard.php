<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\RenderController;
use Native\Asgard\Http\StreamController;

$prefix = trim(config('asgard.path', '_asgard'), '/');

// Deliberately outside the `web` group: no session, no CSRF, no cookies.
Route::match(['QUERY', 'POST'], $prefix.'/render', RenderController::class)
    ->middleware(config('asgard.middleware', []))
    ->name('asgard.render');

Route::match(['QUERY', 'POST'], $prefix.'/stream', StreamController::class)
    ->middleware(config('asgard.middleware', []))
    ->name('asgard.stream');
