<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\Hydrator;

class RenderController
{
    public const PROTOCOL = 1;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->getMethod() === 'POST'
            && strtoupper((string) $request->headers->get('X-Asgard-Method')) !== 'QUERY') {
            return $this->error(405, 'method_not_allowed', 'POST requires the X-Asgard-Method: QUERY header.')
                ->header('Allow', 'QUERY, POST');
        }

        try {
            $payload = $this->payload($request);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Plain array lookups only. The wire strings never name a class or method.
        $class = $this->registry->resolve($payload['component']);
        $method = $class ? (ComponentMetadata::for($class)->regions[$payload['region']] ?? null) : null;

        if ($class === null || $method === null) {
            return response()->json(['error' => 'unknown_component'], 404);
        }

        $component = $this->container->make($class);
        $component->setAsgardContext($payload['region'], $payload['platform']);

        try {
            Hydrator::hydrate($component, $payload['state']);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $result = $this->container->call([$component, $method]);

        try {
            $fragment = FragmentValidator::validate($result);
        } catch (InvalidFragment $e) {
            report($e);

            return config('app.debug')
                ? $this->error(500, 'invalid_fragment', $class.'::'.$method.'() returned an invalid fragment. '.$e->getMessage())
                : response()->json(['error' => 'invalid_fragment'], 500);
        }

        return response()->json([
            'protocol' => self::PROTOCOL,
            'region' => $payload['region'],
            'fragment' => $fragment,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{component: string, region: string, state: array<string, mixed>, platform: string}
     */
    protected function payload(Request $request): array
    {
        $header = $request->headers->get('Asgard-Protocol');
        if ($header !== null && $header !== (string) self::PROTOCOL) {
            throw new InvalidRequest('Unsupported Asgard-Protocol header.');
        }

        try {
            $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRequest('Body must be valid JSON.');
        }

        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw new InvalidRequest('Body must be a JSON object.');
        }

        if (($body['protocol'] ?? null) !== self::PROTOCOL) {
            throw new InvalidRequest('protocol must be 1.');
        }

        $component = $body['component'] ?? null;
        if (! is_string($component) || strlen($component) > 255 || ! preg_match(ComponentRegistry::ADDRESS_PATTERN, $component)) {
            throw new InvalidRequest('component must be a dot-notation address.');
        }

        $region = $body['region'] ?? null;
        if (! is_string($region) || ! preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $region)) {
            throw new InvalidRequest('region must be a region name.');
        }

        $state = $body['state'] ?? [];
        if (! is_array($state) || ($state !== [] && array_is_list($state))) {
            throw new InvalidRequest('state must be a JSON object.');
        }

        $platform = $body['platform'] ?? null;
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw new InvalidRequest('platform must be ios or android.');
        }

        return compact('component', 'region', 'state', 'platform');
    }

    protected function error(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
