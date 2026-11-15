<?php

namespace Native\Asgard\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Native\Asgard\Security\Base64Url;

/**
 * Push counters kept in a cache store. Asgard::push() and Asgard::to()
 * bump them, and each open stream polls them and re-renders when one has
 * changed. A region's version token (token()) is a digest of the counters
 * it watches.
 *
 * The counters and their keys:
 *
 *     asgard:push:{address}                      everyone on a component
 *     asgard:push:{address}#{region}             everyone on one region of it
 *     asgard:push:@{channel}                     every stream in a channel
 *     asgard:push:@{channel}|{address}           a component, in a channel
 *     asgard:push:@{channel}|{address}#{region}  one region of it, in a channel
 *
 * {channel} is the sha1 of the channel name, so it is always 40 hex
 * characters whatever the name holds, and keys stay short enough for any
 * store. Addresses are [a-z0-9_.] and regions never hold '#' or '|', so no
 * two counters can share a key.
 *
 * Streams compare with !==, never <, so a counter that is evicted or reset
 * still counts as a change.
 *
 * Region counters (the two with a #{region}) also keep a short patch log,
 * one entry per bump, keyed by the counter's value after it:
 *
 *     asgard:patch:{counter key without asgard:push:}@{value}
 *
 * A patch's entry holds the patch, already rendered to JSON, and the
 * element types in it. Any other bump of a region counter leaves a
 * `plain` entry, so a stream knows straight away that it cannot patch past
 * it. The log keeps the newest `patch_log` entries of each counter, each
 * for at most PATCH_TTL seconds.
 */
class PushVersions
{
    public const PREFIX = 'asgard:push:';

    public const SHARED_PREFIX = 'asgard:shared:';

    public const PATCH_PREFIX = 'asgard:patch:';

    /** Seconds a shared region's rendered answer is kept for other streams. */
    public const SHARED_TTL = 60;

    /** Seconds a patch log entry is kept. */
    public const PATCH_TTL = 60;

    /**
     * @param  int  $patchLog  entries kept per region counter. 0 keeps none,
     *                         so every patch reaches devices as a signal.
     */
    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected int $patchLog = 256,
    ) {}

    public function bump(string $address, ?string $region = null): void
    {
        if ($region === null) {
            $this->increment(self::componentKey($address));

            return;
        }

        $this->bumpPlain(self::regionKey($address, $region));
    }

    /** Bump a channel's counter, or one for a component or region in it. */
    public function bumpChannel(string $channel, ?string $address = null, ?string $region = null): void
    {
        match (true) {
            $address === null => $this->increment(self::channelKey($channel)),
            $region === null => $this->increment(self::channelComponentKey($channel, $address)),
            default => $this->bumpPlain(self::channelRegionKey($channel, $address, $region)),
        };
    }

    /**
     * Bump region counters (regionKey() or channelRegionKey()) for one
     * patch, logging it against each one's new value.
     *
     * @param  list<string>  $keys
     * @param  array{patch: string, types: list<string>}  $entry  the patch's JSON and its element types
     */
    public function patch(array $keys, array $entry): void
    {
        $repository = $this->repository();

        foreach ($keys as $key) {
            $value = $this->increment($key);

            if ($this->patchLog > 0) {
                $repository->put(self::logKey($key, $value), $entry, self::PATCH_TTL);

                if ($value > $this->patchLog) {
                    $repository->forget(self::logKey($key, $value - $this->patchLog));
                }
            }
        }
    }

    /** How many entries each region counter's log keeps. */
    public function patchLog(): int
    {
        return $this->patchLog;
    }

    /**
     * Log entries by their keys (logKey()), in one many() call. A missing
     * entry is null.
     *
     * @param  list<string>  $keys
     * @return array<string, array{patch?: string, types?: list<string>, plain?: true}|null>
     */
    public function entries(array $keys): array
    {
        return $this->repository()->many($keys);
    }

    public static function logKey(string $counter, int $value): string
    {
        return self::PATCH_PREFIX.substr($counter, strlen(self::PREFIX)).'@'.$value;
    }

    /**
     * Every counter one subscription watches, read in a single many() call.
     *
     * `component` holds the counters that re-render every region: the
     * address's own, then each channel's and each channel's for this
     * address. `regions` holds, per region, the counters that re-render just
     * that region. A stream re-renders a region when either list changed.
     *
     * @param  list<string>  $regions
     * @param  list<string>  $channels
     * @return array{component: list<int>, regions: array<string, list<int>>}
     */
    public function snapshot(string $address, array $regions, array $channels = []): array
    {
        return $this->snapshots([['address' => $address, 'regions' => $regions, 'channels' => $channels]])[0];
    }

    /**
     * snapshot() for several subscriptions at once, keyed as given, still
     * in a single many() call. A stream reads every subscription it
     * carries this way on each poll.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, array{address: string, regions: list<string>, channels: list<string>}>  $watches
     * @return array<TKey, array{component: list<int>, regions: array<string, list<int>>}>
     */
    public function snapshots(array $watches): array
    {
        $plans = [];
        $keys = [];

        foreach ($watches as $id => $watch) {
            $component = [self::componentKey($watch['address'])];
            foreach ($watch['channels'] as $channel) {
                $component[] = self::channelKey($channel);
                $component[] = self::channelComponentKey($channel, $watch['address']);
            }

            $perRegion = [];
            foreach ($watch['regions'] as $region) {
                $perRegion[$region] = [self::regionKey($watch['address'], $region)];
                foreach ($watch['channels'] as $channel) {
                    $perRegion[$region][] = self::channelRegionKey($channel, $watch['address'], $region);
                }
            }

            $plans[$id] = [$component, $perRegion];
            array_push($keys, ...$component, ...array_merge(...array_values($perRegion)));
        }

        if ($keys === []) {
            return [];
        }

        $values = $this->repository()->many(array_values(array_unique($keys)));
        $read = fn (array $keys) => array_map(fn ($key) => (int) ($values[$key] ?? 0), $keys);

        return array_map(fn (array $plan) => [
            'component' => $read($plan[0]),
            'regions' => array_map($read, $plan[1]),
        ], $plans);
    }

    /**
     * A region's version token (Asgard protocol §8.1): a short, opaque digest of
     * the counters it watches, the channels they belong to and the region
     * itself. It changes whenever a push would re-render the region, so a
     * device that holds the current token already has the current fragment.
     *
     * @param  list<string>  $channels
     * @param  array{component: list<int>, regions: array<string, list<int>>}  $snapshot
     */
    public static function token(string $address, string $region, array $channels, array $snapshot): string
    {
        $digest = hash('sha256', json_encode(
            [$address, $region, $channels, $snapshot['component'], $snapshot['regions'][$region] ?? []],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), true);

        return Base64Url::encode(substr($digest, 0, 12));
    }

    public static function componentKey(string $address): string
    {
        return self::PREFIX.$address;
    }

    public static function regionKey(string $address, string $region): string
    {
        // Addresses never contain '#', so this cannot collide with another address.
        return self::PREFIX.$address.'#'.$region;
    }

    public static function channelKey(string $channel): string
    {
        // '@' never starts an address, and the hash keeps the key a fixed shape.
        return self::PREFIX.'@'.sha1($channel);
    }

    public static function channelComponentKey(string $channel, string $address): string
    {
        return self::channelKey($channel).'|'.$address;
    }

    public static function channelRegionKey(string $channel, string $address, string $region): string
    {
        return self::channelComponentKey($channel, $address).'#'.$region;
    }

    /**
     * A shared region's answer, rendered by the first stream that needs it
     * and kept for SHARED_TTL seconds so every other stream reuses it. The
     * key holds everything the answer depends on: address, region,
     * canonical state (RFC 8785, Asgard protocol §5.1), version and the
     * element types the client advertised, which decide what a fragment
     * may use. No user, which is
     * why a shared region renders as a guest.
     *
     * The state is the whole object the client sent, keys no property takes
     * included. A client sends whatever it wants the server to know about it
     * (its platform, say) as state, and a render may read it through a
     * property on one class and ignore it on another, so leaving any key out
     * could hand one client's render to another. HTTP headers are not part
     * of the key, so a shared region must not depend on them.
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(): array  $render
     * @param  list<string>  $elements
     */
    public function shared(string $address, string $region, array $state, string $version, Closure $render, array $elements = []): array
    {
        sort($elements, SORT_STRING);

        $key = self::SHARED_PREFIX.hash('sha256', json_encode(
            [$address, $region, CanonicalState::encode($state), $version, $elements],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $repository = $this->repository();
        $answer = $repository->get($key);

        if (is_array($answer)) {
            return $answer;
        }

        $answer = $render();
        $repository->put($key, $answer, self::SHARED_TTL);

        return $answer;
    }

    /** A region counter bump that is not a patch, marked in its log. */
    protected function bumpPlain(string $key): void
    {
        $value = $this->increment($key);

        if ($this->patchLog > 0) {
            $this->repository()->put(self::logKey($key, $value), ['plain' => true], self::PATCH_TTL);
        }
    }

    /** The counter's value after the bump. */
    protected function increment(string $key): int
    {
        $repository = $this->repository();

        // Some stores (database) refuse to increment a missing key.
        $value = $repository->increment($key);

        if ($value === false) {
            $repository->add($key, 0);
            $value = $repository->increment($key);
        }

        return (int) $value;
    }

    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
