<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\SecureRequest;

/**
 * Request checks shared by the render and stream endpoints, so both apply
 * exactly the same rules to the untrusted body. The body they read is the
 * one SealAsgardMessages verified and decrypted.
 */
trait ReadsAsgardRequests
{
    public const PROTOCOL = 1;

    public const REGION_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/D';

    /**
     * Protocol header, JSON object body, protocol 1 and a component address.
     *
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
        $header = $request->headers->get('Asgard-Protocol');
        if ($header !== null && $header !== (string) self::PROTOCOL) {
            throw new InvalidRequest('Unsupported Asgard-Protocol header.');
        }

        try {
            $body = json_decode($this->secure($request)->body, true, 64, JSON_THROW_ON_ERROR);
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

        return $body;
    }

    /** The request as the middleware verified and decrypted it. */
    protected function secure(Request $request): SecureRequest
    {
        $secure = $request->attributes->get(SecureRequest::ATTRIBUTE);

        if (! $secure instanceof SecureRequest) {
            throw new \LogicException('Asgard endpoints must run behind SealAsgardMessages.');
        }

        return $secure;
    }

    protected function regionName(mixed $region, string $field = 'region'): string
    {
        if (! is_string($region) || ! preg_match(self::REGION_PATTERN, $region)) {
            throw new InvalidRequest("{$field} must be a region name.");
        }

        return $region;
    }

    /** @return array<string, mixed> */
    protected function state(mixed $state): array
    {
        $state ??= [];
        if (! is_array($state) || ($state !== [] && array_is_list($state))) {
            throw new InvalidRequest('state must be a JSON object.');
        }

        return $state;
    }

    protected function platform(mixed $platform): string
    {
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw new InvalidRequest('platform must be ios or android.');
        }

        return $platform;
    }

    protected function invalidFragment(InvalidFragment $e, string $class, string $method): JsonResponse
    {
        report($e);

        return config('app.debug')
            ? $this->error(500, 'invalid_fragment', $class.'::'.$method.'() returned an invalid fragment. '.$e->getMessage())
            : response()->json(['error' => 'invalid_fragment'], 500);
    }

    protected function error(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
