<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Asgard\Support\ConfiguredHeaders;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `asgard.headers` to every answer from an Asgard endpoint, errors
 * and the stream included. It runs outside every other check, so the
 * headers go on whatever answers, sealed or plain. The signature covers a
 * fixed list of headers, so these leave it as it was.
 */
class AddConfiguredHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return ConfiguredHeaders::apply($next($request));
    }
}
