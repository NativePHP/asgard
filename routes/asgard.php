<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\CallController;
use Native\Asgard\Http\MethodNotAllowed;
use Native\Asgard\Http\Middleware\LimitUploadSize;
use Native\Asgard\Http\Middleware\RequireQuery;
use Native\Asgard\Http\Middleware\SealAsgardMessages;
use Native\Asgard\Http\RenderController;
use Native\Asgard\Http\RoutesController;
use Native\Asgard\Http\SessionController;
use Native\Asgard\Http\StreamController;
use Native\Asgard\Http\UploadController;

// `asgard.path` is the client's {base}, below the app's URL. The endpoint
// names are the protocol's (Asgard protocol §8).
$prefix = trim(config('asgard.path', '_asgard'), '/');
$middleware = config('asgard.middleware', []);

// The usual verbs an endpoint does not take get the protocol's plain 405.
// Rarer ones are answered the same way by the service provider.
$refused = ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

// Deliberately outside the `web` group: no session, no CSRF, no cookies.
// The handshake creates a session, so it is a plain POST and never QUERY.
Route::post($prefix.'/session', SessionController::class)
    ->middleware($middleware)
    ->name('asgard.session');

Route::match([...$refused, 'QUERY'], $prefix.'/session', MethodNotAllowed::class);

// Signature checks run first, so the app's middleware (bearer auth, say)
// only ever sees a request that is known to be intact. Calls and uploads
// are unsafe, so their signature must carry a nonce.
$endpoints = [
    'render' => [RenderController::class, [SealAsgardMessages::class]],
    'call' => [CallController::class, [SealAsgardMessages::class.':nonce']],
    // The size is checked before the body is hashed or decrypted.
    'upload' => [UploadController::class, [LimitUploadSize::class, SealAsgardMessages::class.':nonce']],
    'stream' => [StreamController::class, [SealAsgardMessages::class]],
    'routes' => [RoutesController::class, [SealAsgardMessages::class]],
];

foreach ($endpoints as $name => [$controller, $checks]) {
    Route::match(['QUERY', 'POST'], $prefix.'/'.$name, $controller)
        ->middleware([RequireQuery::class, ...$checks, ...$middleware])
        ->name('asgard.'.$name);

    Route::match($refused, $prefix.'/'.$name, MethodNotAllowed::class);
}
