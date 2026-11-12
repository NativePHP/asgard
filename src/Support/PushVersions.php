<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Push counters kept in a cache store. Asgard::push() and Asgard::to()
 * bump them, and each open stream polls them and re-renders when one has
 * changed.
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
     * Every counter one stream watches, read in a single many() call.
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
        $component = [self::componentKey($address)];
        foreach ($channels as $channel) {
            $component[] = self::channelKey($channel);
            $component[] = self::channelComponentKey($channel, $address);
        }

        $perRegion = [];
        foreach ($regions as $region) {
            $perRegion[$region] = [self::regionKey($address, $region)];
            foreach ($channels as $channel) {
                $perRegion[$region][] = self::channelRegionKey($channel, $address, $region);
            }
        }

        $values = $this->repository()->many(array_merge($component, ...array_values($perRegion)));
        $read = fn (array $keys) => array_map(fn ($key) => (int) ($values[$key] ?? 0), $keys);

        return [
            'component' => $read($component),
            'regions' => array_map($read, $perRegion),
        ];
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
