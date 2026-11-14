<?php

namespace Native\Asgard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The 405 for a method an endpoint does not take (Asgard protocol §7.3,
 * §8). Plain and unsigned, like every early rejection. `{base}/session`
 * takes only POST. Every other endpoint takes QUERY, or POST with
 * `X-Asgard-Method: QUERY`.
 *
 * The routes send the usual verbs here. Anything rarer reaches the
 * framework as a MethodNotAllowedHttpException, and the service provider
 * answers that with the same body.
 */
class MethodNotAllowed
{
    public const SESSION_ALLOW = 'POST';

    public const ALLOW = 'QUERY, POST';

    public function __invoke(Request $request): JsonResponse
    {
        return self::response(self::endpoint($request) === 'session' ? self::SESSION_ALLOW : self::ALLOW);
    }

    public static function response(string $allow, string $message = 'This endpoint does not take that method.'): JsonResponse
    {
        return response()
            ->json(['error' => 'method_not_allowed', 'message' => $message], 405)
            ->header('Allow', $allow);
    }

    /** Whether the request is for one of the Asgard endpoints, and which. */
    public static function endpoint(Request $request): ?string
    {
        $prefix = trim((string) config('asgard.path', '_asgard'), '/');
        $path = trim($request->path(), '/');
        $name = $prefix === '' ? $path : (str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix) + 1) : null);

        return in_array($name, ['session', 'render', 'stream', 'call', 'upload', 'routes'], true) ? $name : null;
    }
}
