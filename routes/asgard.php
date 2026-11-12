<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\Middleware\SealAsgardMessages;
use Native\Asgard\Http\RenderController;
use Native\Asgard\Http\SessionController;
use Native\Asgard\Http\StreamController;

$prefix = trim(config('asgard.path', '_asgard'), '/');
$middleware = config('asgard.middleware', []);

// Deliberately outside the `web` group: no session, no CSRF, no cookies.
Route::post($prefix.'/session', SessionController::class)
    ->middleware($middleware)
    ->name('asgard.session');

// Signature checks run first, so the app's middleware (bearer auth, say)
// only ever sees a request that is known to be intact.
Route::match(['QUERY', 'POST'], $prefix.'/render', RenderController::class)
    ->middleware([SealAsgardMessages::class, ...$middleware])
    ->name('asgard.render');

Route::match(['QUERY', 'POST'], $prefix.'/stream', StreamController::class)
    ->middleware([SealAsgardMessages::class, ...$middleware])
    ->name('asgard.stream');
