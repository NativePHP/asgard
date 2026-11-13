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
 */
class PushVersions
{
    public const PREFIX = 'asgard:push:';

    public const SHARED_PREFIX = 'asgard:shared:';

    /** Seconds a shared region's rendered answer is kept for other streams. */
    public const SHARED_TTL = 60;

    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
    ) {}

    public function bump(string $address, ?string $region = null): void
    {
        $this->increment($region === null ? self::componentKey($address) : self::regionKey($address, $region));
    }

    /** Bump a channel's counter, or one for a component or region in it. */
    public function bumpChannel(string $channel, ?string $address = null, ?string $region = null): void
    {
        $this->increment(match (true) {
            $address === null => self::channelKey($channel),
            $region === null => self::channelComponentKey($channel, $address),
            default => self::channelRegionKey($channel, $address, $region),
        });
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
     * A region's version token (protocol §8.1): a short, opaque digest of
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
     * platform, canonical state and version. No user, which is why a
     * shared region renders as a guest.
     *
     * @param  array<string, mixed>  $state
     * @param  Closure(): array  $render
     */
    public function shared(string $address, string $region, string $platform, array $state, string $version, Closure $render): array
    {
        $key = self::SHARED_PREFIX.hash('sha256', json_encode(
            [$address, $region, $platform, self::canonical($state), $version],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
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

    /** Objects with sorted keys, all the way down, so equal state gives one key. */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    protected function increment(string $key): void
    {
        $repository = $this->repository();

        // Some stores (database) refuse to increment a missing key.
        if ($repository->increment($key) === false) {
            $repository->add($key, 0);
            $repository->increment($key);
        }
    }

    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
