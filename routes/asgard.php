<?php

use Illuminate\Support\Facades\Route;
use Native\Asgard\Http\CallController;
use Native\Asgard\Http\MethodNotAllowed;
use Native\Asgard\Http\Middleware\AddConfiguredHeaders;
use Native\Asgard\Http\Middleware\LimitUploadSize;
use Native\Asgard\Http\Middleware\RequireProtocolAndCoding;
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
// asgard.headers go on every answer, so their middleware wraps all the rest.
Route::post($prefix.'/session', SessionController::class)
    ->middleware([AddConfiguredHeaders::class, ...$middleware])
    ->name('asgard.session');

Route::match([...$refused, 'QUERY'], $prefix.'/session', MethodNotAllowed::class)
    ->middleware(AddConfiguredHeaders::class);

// Signature checks run first, so the app's middleware (bearer auth, say)
// only ever sees a request that is known to be intact. Calls and uploads
// are unsafe, so their signature must carry a nonce. Streams and the route
// list are reads, so theirs must not. A render is either, by its body.
$endpoints = [
    'render' => [RenderController::class, [SealAsgardMessages::class.':render']],
    'call' => [CallController::class, [SealAsgardMessages::class.':unsafe']],
    // The size is checked before the body is hashed or decrypted.
    'upload' => [UploadController::class, [LimitUploadSize::class, SealAsgardMessages::class.':unsafe']],
    'stream' => [StreamController::class, [SealAsgardMessages::class.':read']],
    'routes' => [RoutesController::class, [SealAsgardMessages::class.':read']],
];

foreach ($endpoints as $name => [$controller, $checks]) {
    Route::match(['QUERY', 'POST'], $prefix.'/'.$name, $controller)
        ->middleware([AddConfiguredHeaders::class, RequireQuery::class, RequireProtocolAndCoding::class, ...$checks, ...$middleware])
        ->name('asgard.'.$name);

    Route::match($refused, $prefix.'/'.$name, MethodNotAllowed::class)
        ->middleware(AddConfiguredHeaders::class);
}
