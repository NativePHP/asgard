<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Push counters kept in a cache store. One counter per component address,
 * and one per address and region. Asgard::push() bumps them, and each open
 * stream polls them and re-renders when one has changed.
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

    /**
     * @param  list<string>  $regions
     * @return array{component: int, regions: array<string, int>}
     */
    public function snapshot(string $address, array $regions): array
    {
        $keys = [self::componentKey($address)];
        foreach ($regions as $region) {
            $keys[] = self::regionKey($address, $region);
        }

        $values = $this->repository()->many($keys);

        $snapshot = ['component' => (int) ($values[$keys[0]] ?? 0), 'regions' => []];
        foreach ($regions as $i => $region) {
            $snapshot['regions'][$region] = (int) ($values[$keys[$i + 1]] ?? 0);
        }

        return $snapshot;
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
