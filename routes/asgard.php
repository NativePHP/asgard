<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\CallController;
use Native\Asgard\Http\Middleware\LimitUploadSize;
use Native\Asgard\Http\Middleware\SealAsgardMessages;
use Native\Asgard\Http\RenderController;
use Native\Asgard\Http\RoutesController;
use Native\Asgard\Http\SessionController;
use Native\Asgard\Http\StreamController;
use Native\Asgard\Http\UploadController;

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

// The server half of a #[Server] method. Unsafe, so it needs a nonce.
Route::match(['QUERY', 'POST'], $prefix.'/call', CallController::class)
    ->middleware([SealAsgardMessages::class, ...$middleware])
    ->name('asgard.call');

// A file for an #[Upload] method. Unsafe, so it needs a nonce. Its size is
// checked before the body is hashed or decrypted.
Route::match(['QUERY', 'POST'], $prefix.'/upload', UploadController::class)
    ->middleware([LimitUploadSize::class, SealAsgardMessages::class, ...$middleware])
    ->name('asgard.upload');

Route::match(['QUERY', 'POST'], $prefix.'/stream', StreamController::class)
    ->middleware([SealAsgardMessages::class, ...$middleware])
    ->name('asgard.stream');

Route::match(['QUERY', 'POST'], $prefix.'/routes', RoutesController::class)
    ->middleware([SealAsgardMessages::class, ...$middleware])
    ->name('asgard.routes');
