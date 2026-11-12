<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;

/**
 * Asgard sessions (protocol §6), kept in a cache store.
 *
 * A session is its secret and nothing else. Every accepted request puts it
 * back with a fresh lifetime, so it expires after `lifetime` seconds idle.
 * Nonces from action calls are kept here too, until their signature expires.
 */
class SessionStore
{
    public const PREFIX = 'asgard:session:';

    public const NONCE_PREFIX = 'asgard:nonce:';

    public const ID_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected int $lifetime = 1800,
    ) {}

    /** Store a new session and return its id. */
    public function create(string $secret): string
    {
        $id = Base64Url::encode(random_bytes(18));

        $this->repository()->put(self::PREFIX.$id, Base64Url::encode($secret), $this->lifetime);

        return $id;
    }

    public function find(string $id): ?SessionKeys
    {
        $secret = $this->secret($id);

        return $secret === null ? null : SessionKeys::fromSecret($secret);
    }

    /** Slide the expiry forward. */
    public function touch(string $id): void
    {
        $secret = $this->secret($id);

        if ($secret !== null) {
            $this->repository()->put(self::PREFIX.$id, Base64Url::encode($secret), $this->lifetime);
        }
    }

    /**
     * Remember a nonce until its signature expires. False when it has been
     * seen before, which makes the request a replay.
     */
    public function claimNonce(string $session, string $nonce, int $expires, int $now): bool
    {
        $ttl = max(1, $expires - $now + Wire::MAX_SKEW);

        return $this->repository()->add(self::NONCE_PREFIX.$session.':'.$nonce, 1, $ttl);
    }

    public function lifetime(): int
    {
        return $this->lifetime;
    }

    protected function secret(string $id): ?string
    {
        if (! preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $stored = $this->repository()->get(self::PREFIX.$id);
        $secret = is_string($stored) ? Base64Url::decode($stored) : null;

        return $secret !== null && strlen($secret) === 32 ? $secret : null;
    }

    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
