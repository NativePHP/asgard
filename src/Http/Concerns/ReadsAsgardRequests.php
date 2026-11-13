<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\SecureRequest;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\Channels;
use Native\Asgard\Support\Hydrator;

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
     * Protocol header, JSON object body and protocol 1.
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

        return $body;
    }

    protected function component(mixed $component, string $field = 'component'): string
    {
        if (! is_string($component) || strlen($component) > 255 || ! preg_match(ComponentRegistry::ADDRESS_PATTERN, $component)) {
            throw new InvalidRequest("{$field} must be a dot-notation address.");
        }

        return $component;
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

    /**
     * The channels a component joins, from channels() on an instance
     * hydrated with this state. The app's middleware has already run, so
     * auth() sees the request's user.
     *
     * A bad answer is a bug in the app. With app.debug on it throws. In
     * production it is reported, the bad entries are dropped and the first
     * 16 good ones are kept, so the screen still works and still gets
     * pushes to everyone. Any channel it misses only means fewer
     * re-renders, never someone else's fragment.
     *
     * @param  class-string<ServerComponent>  $class
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    protected function channels(string $class, string $platform, array $state): array
    {
        $component = $this->container->make($class);
        $component->setAsgardContext('', $platform);

        Hydrator::hydrate($component, $state);

        return $this->channelsOf($component);
    }

    /**
     * channels() for a component that is already hydrated.
     *
     * @return list<string>
     */
    protected function channelsOf(ServerComponent $component): array
    {
        $class = $component::class;
        $names = [];
        $problems = [];
        foreach ($component->channels() as $i => $channel) {
            try {
                $names[] = Channels::name($channel);
            } catch (InvalidArgumentException $e) {
                $problems[] = "[{$i}] ".$e->getMessage();
            }
        }

        $names = array_values(array_unique($names));

        if (count($names) > Channels::MAX) {
            $problems[] = 'it named '.count($names).' channels and a stream joins at most '.Channels::MAX.'.';
            $names = array_slice($names, 0, Channels::MAX);
        }

        if ($problems !== []) {
            $e = new InvalidArgumentException("{$class}::channels() returned bad channels: ".implode(' ', $problems));

            if (config('app.debug')) {
                throw $e;
            }

            report($e);
        }

        return $names;
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
