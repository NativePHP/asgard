<?php

namespace Native\Asgard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\ScreenRegistry;

/**
 * The server's screen routes (protocol §12, "Routes"). Sealed like render,
 * so only a client holding a session can read the list, and a proxy that
 * logs bodies learns nothing from it.
 *
 *     → {"protocol": 1}
 *     ← {"protocol": 1, "version": "…", "routes": [{"uri": "/promos/{id}", "screen": "promos.show"}]}
 */
class RoutesController
{
    use ReadsAsgardRequests;

    public function __construct(protected ScreenRegistry $screens) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->routesBody($request);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        return response()->json([
            'protocol' => self::PROTOCOL,
            'version' => $this->screens->version(),
            'routes' => $this->screens->routes(),
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Protocol header and a body of exactly {"protocol": 1}. */
    protected function routesBody(Request $request): void
    {
        $header = $request->headers->get('Asgard-Protocol');
        if ($header !== null && $header !== (string) self::PROTOCOL) {
            throw new InvalidRequest('Unsupported Asgard-Protocol header.');
        }

        $body = json_decode($this->secure($request)->body, true);

        if (! is_array($body) || array_is_list($body) || ($body['protocol'] ?? null) !== self::PROTOCOL || count($body) !== 1) {
            throw new InvalidRequest('Body must be {"protocol": 1}.');
        }
    }
}
