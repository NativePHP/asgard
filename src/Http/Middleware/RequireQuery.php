<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Asgard\Http\MethodNotAllowed;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every endpoint but the handshake takes QUERY, or POST with
 * `X-Asgard-Method: QUERY` where something in front of the server blocks
 * QUERY (Asgard protocol §8). A POST without the header gets the plain 405
 * of §7.3. Runs first, ahead of the upload size check and the signature
 * checks.
 */
class RequireQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getRealMethod() === 'POST'
            && strtoupper((string) $request->headers->get('X-Asgard-Method')) !== 'QUERY') {
            return MethodNotAllowed::response(MethodNotAllowed::ALLOW, 'POST requires the X-Asgard-Method: QUERY header.');
        }

        return $next($request);
    }
}
