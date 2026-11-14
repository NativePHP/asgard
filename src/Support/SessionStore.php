<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;

/**
 * Asgard sessions (Asgard protocol §6), kept in a cache store.
 *
 * A session is its secret and the extra element types the client said it
 * can render at the handshake (`elements`, Asgard protocol §6.1). Every
 * accepted request puts it back with a fresh lifetime, so it expires after
 * `lifetime` seconds idle. Nonces from actions, calls and uploads are kept
 * here too, until their signature expires.
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

    /**
     * Store a new session and return its id.
     *
     * @param  list<string>  $elements  the extra types the client advertised
     */
    public function create(string $secret, array $elements = []): string
    {
        $id = Base64Url::encode(random_bytes(18));

        $this->put($id, $secret, $elements);

        return $id;
    }

    public function find(string $id): ?SessionKeys
    {
        return $this->open($id)['keys'] ?? null;
    }

    /**
     * The session's keys and advertised element types, or null when the
     * session is unknown or expired.
     *
     * @return array{keys: SessionKeys, elements: list<string>}|null
     */
    public function open(string $id): ?array
    {
        $record = $this->record($id);

        return $record === null ? null : [
            'keys' => SessionKeys::fromSecret($record['secret']),
            'elements' => $record['elements'],
        ];
    }

    /** Slide the expiry forward. */
    public function touch(string $id): void
    {
        $record = $this->record($id);

        if ($record !== null) {
            $this->put($id, $record['secret'], $record['elements']);
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

    /** @param  list<string>  $elements */
    protected function put(string $id, string $secret, array $elements): void
    {
        $this->repository()->put(self::PREFIX.$id, [
            'secret' => Base64Url::encode($secret),
            'elements' => $elements,
        ], $this->lifetime);
    }

    /** @return array{secret: string, elements: list<string>}|null */
    protected function record(string $id): ?array
    {
        if (! preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $stored = $this->repository()->get(self::PREFIX.$id);

        if (! is_array($stored) || ! is_string($stored['secret'] ?? null)) {
            return null;
        }

        $secret = Base64Url::decode($stored['secret']);
        $elements = $stored['elements'] ?? [];

        if ($secret === null || strlen($secret) !== 32 || ! is_array($elements)) {
            return null;
        }

        return ['secret' => $secret, 'elements' => array_values(array_filter($elements, 'is_string'))];
    }

    protected function repository(): Repository
    {
        return $this->cache->store($this->store);
    }
}
