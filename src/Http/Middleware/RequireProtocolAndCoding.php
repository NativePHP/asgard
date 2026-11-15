<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Asgard\Http\SessionController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first two checks of Asgard protocol §7.6, ahead of the upload size
 * check and everything in SealAsgardMessages:
 *
 * 1. `Asgard-Protocol` is a version this server speaks, else the plain
 *    `422 unsupported_protocol` with the versions it does speak (§6.2).
 * 2. `Content-Encoding` is `aes128gcm`, else the plain
 *    `415 unsupported_coding` (§7.4). Requests are never compressed.
 *
 * Both hold whether or not the request is signed. Whether the version is
 * the one the session was made with is checked once the session is found.
 */
class RequireProtocolAndCoding
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::version($request) === null) {
            return response()->json([
                'error' => 'unsupported_protocol',
                'protocols' => SessionController::PROTOCOLS,
                'message' => 'Asgard-Protocol is missing or is not a version this server speaks.',
            ], 422);
        }

        if (strtolower(trim((string) $request->headers->get('Content-Encoding'))) !== 'aes128gcm') {
            return response()->json([
                'error' => 'unsupported_coding',
                'message' => 'Request bodies must use Content-Encoding: aes128gcm.',
            ], 415, ['Accept-Encoding' => 'aes128gcm']);
        }

        return $next($request);
    }

    /** The request's Asgard-Protocol, when it is a version this server speaks. */
    public static function version(Request $request): ?int
    {
        $header = $request->headers->get('Asgard-Protocol');

        if ($header === null || ! preg_match('/^[1-9][0-9]{0,8}$/D', $header)) {
            return null;
        }

        return in_array((int) $header, SessionController::PROTOCOLS, true) ? (int) $header : null;
    }
}
