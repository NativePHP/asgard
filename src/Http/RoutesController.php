<?php

namespace Native\Asgard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\ScreenRegistry;

/**
 * The server's screen routes (Asgard protocol §12.3). Sealed like render,
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

    /**
     * A body of {"protocol": 1}. Other members are
     * ignored (§5.3).
     */
    protected function routesBody(Request $request): void
    {
        $body = json_decode($this->secure($request)->body, true);

        if (! is_array($body) || array_is_list($body) || ($body['protocol'] ?? null) !== self::PROTOCOL) {
            throw new InvalidRequest('Body must be a JSON object with "protocol": 1.');
        }
    }
}
