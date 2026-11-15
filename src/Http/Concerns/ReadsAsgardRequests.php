<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Native\Asgard\ClientCache;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Exceptions\TooManyRegions;
use Native\Asgard\Http\RenderController;
use Native\Asgard\Http\SecureRequest;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\Channels;
use Native\Asgard\Support\ComponentMetadata;
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

    /** At most this many entries in a request's `cache` member. */
    public const MAX_CACHE_ENTRIES = 64;

    /** The longest TTL a client may declare: a year. */
    public const MAX_CACHE_TTL = 31_536_000;

    /**
     * A JSON object body with protocol 1. RequireProtocolAndCoding has
     * already checked the Asgard-Protocol header.
     *
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
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

    /**
     * The extra element types this session's client said it can render,
     * which a fragment may use beside the core ones (Asgard protocol §9.3).
     *
     * @return list<string>
     */
    protected function elements(): array
    {
        return $this->secure($this->container->make('request'))->elements;
    }

    protected function regionName(mixed $region, string $field = 'region'): string
    {
        if (! is_string($region) || ! preg_match(self::REGION_PATTERN, $region)) {
            throw new InvalidRequest("{$field} must be a region name.");
        }

        return $region;
    }

    /**
     * The request's `cache` member: the copies of regions the client holds,
     * keyed by region name, each `{"made_at": ms, "ttl": seconds}`.
     *
     * @return array<string, array{made_at: int, ttl: int}>
     */
    protected function cache(mixed $cache): array
    {
        $cache ??= [];

        if (! is_array($cache) || ($cache !== [] && array_is_list($cache))) {
            throw new InvalidRequest('cache must be a JSON object.');
        }

        if (count($cache) > self::MAX_CACHE_ENTRIES) {
            throw new InvalidRequest('cache holds at most '.self::MAX_CACHE_ENTRIES.' entries.');
        }

        $checked = [];
        foreach ($cache as $region => $entry) {
            $region = (string) $region;
            $field = "cache.{$region}";

            if (! preg_match(self::REGION_PATTERN, $region)) {
                throw new InvalidRequest('cache keys must be region names.');
            }

            if (! is_array($entry) || array_is_list($entry)) {
                throw new InvalidRequest("{$field} must be an object.");
            }

            if (array_diff(array_map('strval', array_keys($entry)), ['made_at', 'ttl']) !== []) {
                throw new InvalidRequest("{$field} has unknown fields.");
            }

            if (! is_int($entry['made_at'] ?? null) || $entry['made_at'] < 1) {
                throw new InvalidRequest("{$field}.made_at must be a positive integer.");
            }

            if (! is_int($entry['ttl'] ?? null) || $entry['ttl'] < 0 || $entry['ttl'] > self::MAX_CACHE_TTL) {
                throw new InvalidRequest("{$field}.ttl must be an integer from 0 to ".self::MAX_CACHE_TTL.'.');
            }

            $checked[$region] = ['made_at' => $entry['made_at'], 'ttl' => $entry['ttl']];
        }

        return $checked;
    }

    /**
     * Hand the component the copies it can use. Names that are not its
     * regions are dropped.
     *
     * @param  array<string, array{made_at: int, ttl: int}>  $cache
     */
    protected function giveClientCache(ServerComponent $component, ComponentMetadata $metadata, array $cache): void
    {
        $caches = [];

        foreach ($cache as $region => $entry) {
            if (isset($metadata->regions[$region])) {
                $caches[$region] = new ClientCache($region, $entry['made_at'], $entry['ttl'], $metadata->maxAge($region));
            }
        }

        $component->setAsgardClientCache($caches);
    }

    /**
     * A list of region names, as render's `regions` and a call's or
     * upload's `regions` carry them. Over asgard.max_regions, nothing in
     * the request is looked at further.
     *
     * @return list<string>
     */
    protected function regionList(mixed $regions, bool $allowEmpty = false): array
    {
        if (! is_array($regions) || ! array_is_list($regions) || (! $allowEmpty && $regions === [])) {
            throw new InvalidRequest($allowEmpty ? 'regions must be a list of region names.' : 'regions must be a list of at least one region name.');
        }

        $max = RenderController::maxRegions();
        if (count($regions) > $max) {
            throw new TooManyRegions("A request names at most {$max} regions.");
        }

        foreach ($regions as $i => $region) {
            $this->regionName($region, "regions[{$i}]");
        }

        if (count(array_unique($regions)) !== count($regions)) {
            throw new InvalidRequest('regions must not repeat a name.');
        }

        return $regions;
    }

    /**
     * The request's `read_only` member: the state keys the server may read
     * but never change. Each one must be a key of `state`, named once.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    protected function readOnly(mixed $readOnly, array $state, string $field = 'read_only'): array
    {
        $readOnly ??= [];

        if (! is_array($readOnly) || ! array_is_list($readOnly)) {
            throw new InvalidRequest("{$field} must be a list of state keys.");
        }

        if (count($readOnly) > count($state)) {
            throw new InvalidRequest("{$field} names more keys than state has.");
        }

        foreach ($readOnly as $i => $key) {
            if (! is_string($key) || ! array_key_exists($key, $state)) {
                throw new InvalidRequest("{$field}[{$i}] must be a key of state.");
            }
        }

        if (count(array_unique($readOnly)) !== count($readOnly)) {
            throw new InvalidRequest("{$field} must not repeat a key.");
        }

        return $readOnly;
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
    protected function channels(string $class, array $state): array
    {
        $component = $this->container->make($class);
        $component->setAsgardContext('');

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
