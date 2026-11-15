<?php

namespace Native\Asgard;

use Carbon\CarbonImmutable;

/**
 * A copy of one region the client said it holds, from the request's
 * `cache` member. `madeAt` is the server's own `made_at` from when it
 * rendered that copy, as the client stored it. `ttl` is how long the
 * client means to keep it, in seconds.
 *
 * The copy is fresh while its age is under the shorter of `ttl` and the
 * region's server max age. A `made_at` up to a minute in the future
 * still counts, for clock skew between servers.
 *
 * A fresh copy with `asgard.cache.grace` seconds or less left is in its
 * grace window. The server renders it anyway, as a normal fragment, so
 * the client gets a new copy before its old one runs out.
 */
final class ClientCache
{
    /** How far in the future a made_at may be and still count. */
    public const SKEW_MS = 60_000;

    public readonly CarbonImmutable $madeAt;

    public function __construct(
        public readonly string $region,
        public readonly int $madeAtMs,
        public readonly int $ttl,
        public readonly ?int $maxAge = null,
    ) {
        $this->madeAt = CarbonImmutable::createFromTimestampMs($madeAtMs);
    }

    /** Seconds since the server rendered the copy. Negative when made_at is ahead of this server's clock. */
    public function age(): float
    {
        return (self::nowMs() - $this->madeAtMs) / 1000;
    }

    /** Within the shorter of ttl and max age. Grace does not count here. */
    public function isFresh(): bool
    {
        $age = self::nowMs() - $this->madeAtMs;

        return $age >= -self::SKEW_MS && $age < $this->limit() * 1000;
    }

    /** Seconds of freshness left. Negative once the copy has run out. */
    public function remaining(): float
    {
        return ($this->limit() * 1000 - (self::nowMs() - $this->madeAtMs)) / 1000;
    }

    /** Fresh, but with `asgard.cache.grace` seconds or less left. */
    public function inGrace(): bool
    {
        return $this->isFresh() && $this->remaining() <= self::grace();
    }

    /** `asgard.cache.grace`, in seconds. */
    public static function grace(): int
    {
        $grace = config('asgard.cache.grace', 30) ?? 30;

        if (! is_numeric($grace) || (int) $grace != $grace || (int) $grace < 0) {
            throw new \InvalidArgumentException('asgard.cache.grace must be a whole number of seconds, 0 or more.');
        }

        return (int) $grace;
    }

    private function limit(): int
    {
        return $this->maxAge === null ? $this->ttl : min($this->ttl, $this->maxAge);
    }

    /**
     * Unix time in milliseconds, from now() so tests can freeze it.
     *
     * @internal
     */
    public static function nowMs(): int
    {
        return now()->getTimestampMs();
    }
}
