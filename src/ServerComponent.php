<?php

namespace Native\Asgard;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use InvalidArgumentException;
use Native\Asgard\Support\ComponentMetadata;

/**
 * Base class for the server half of a component.
 *
 * Public, non-static, typed properties declared on your subclass are client
 * state: they are hydrated from the request before a region method runs.
 * Mark a property #[Locked] to keep the client from writing it.
 *
 * Public methods marked #[Region('name')] render regions.
 *
 * channels() names the channels a stream subscription joins, so
 * Asgard::to() can push to it.
 */
abstract class ServerComponent
{
    private string $asgardRegion = '';

    /** At most this many regions rendered inline, and hints sent, per answer. */
    public const MAX_REFRESH = 16;

    /** @var array<string, true> */
    private array $asgardInline = [];

    /** @var array<string, array{component: string, region?: string}> */
    private array $asgardHints = [];

    /** @var array<string, ClientCache> */
    private array $asgardClientCache = [];

    private bool $asgardInvalidate = false;

    private bool $asgardPush = false;

    /** @internal */
    final public function setAsgardContext(string $region): void
    {
        $this->asgardRegion = $region;
    }

    /**
     * The channels a stream subscription for this component joins when the
     * stream opens, for Asgard::to() pushes. Called once per subscription,
     * on an instance hydrated from its state, after the app's middleware, so
     * auth() works. Render calls it too, before any action, to work out the
     * region's version token:
     *
     *     public function channels(): array
     *     {
     *         return auth()->check()
     *             ? ["promo.{$this->id}", 'user.'.auth()->id()]
     *             : ["promo.{$this->id}"];
     *     }
     *
     * Return channel names (1 to 200 bytes, no control characters) or
     * models, which are named as Laravel broadcasting names them
     * (App.Models.User.5). Repeats are dropped, and a subscription joins at most
     * 16. Only the server works these out; the device never sends them.
     *
     * This is the authorization step. Anyone with the app's key can open a
     * stream with any state, so a channel built from state ("promo.{$id}")
     * is only as private as that state. Build a private channel from the
     * authenticated user, with auth middleware in asgard.middleware, and
     * check auth()->check() first. Render code still authorizes what it
     * shows, since a push re-renders with the stream's own state and user.
     *
     * region() is '' on a stream, because a subscription covers several
     * regions. On render it is the region asked for. Keep channels() from
     * depending on it, or the region is sent again each time the stream
     * opens.
     *
     * @return list<string|HasBroadcastChannel>
     */
    public function channels(): array
    {
        return [];
    }

    /**
     * Ask for other regions to be drawn again, from inside a #[ServerAction]
     * or #[Upload] method.
     *
     * Regions of this component are rendered here, after the method runs,
     * with the state it left and the request's user, and travel in the same
     * answer:
     *
     *     $this->refresh('stats');
     *     $this->refresh('stats', 'chart');
     *
     * Another component is only named. The answer carries a hint, and every
     * live instance of it on this phone that drew the region fetches it
     * again with its own state:
     *
     *     $this->refresh(InboxScreen::class, 'unread');
     *     $this->refresh('app.native.screens.inbox');
     *
     * Leave the region out to mean all of that component's regions. A name
     * that is a region of this component always means that region. Other
     * phones are reached with Asgard::push() or Asgard::to(), not this.
     *
     * An unknown region or component throws, as Asgard::push() does.
     */
    final protected function refresh(string $target, string ...$regions): void
    {
        $own = ComponentMetadata::for(static::class)->regions;

        if (isset($own[$target])) {
            foreach ([$target, ...$regions] as $region) {
                if (! isset($own[$region])) {
                    throw new InvalidArgumentException("[{$region}] is not a #[Region] on [".static::class.'].');
                }

                $this->asgardInline[$region] = true;
            }

            if (count($this->asgardInline) > self::MAX_REFRESH) {
                throw new InvalidArgumentException('An answer renders at most '.self::MAX_REFRESH.' other regions.');
            }

            return;
        }

        if (count($regions) > 1) {
            throw new InvalidArgumentException('A refresh of another component names at most one region.');
        }

        $region = $regions[0] ?? null;

        foreach (app(Asgard::class)->addresses($target, $region) as $address) {
            // A hint for the whole component covers any for one region.
            if (isset($this->asgardHints[$address])) {
                continue;
            }

            $key = $region === null ? $address : $address.'#'.$region;

            if ($region === null) {
                foreach (array_keys($this->asgardHints) as $held) {
                    if (str_starts_with($held, $address.'#')) {
                        unset($this->asgardHints[$held]);
                    }
                }
            }

            $this->asgardHints[$key] = $region === null
                ? ['component' => $address]
                : ['component' => $address, 'region' => $region];
        }

        if (count($this->asgardHints) > self::MAX_REFRESH) {
            throw new InvalidArgumentException('An answer carries at most '.self::MAX_REFRESH.' refresh hints.');
        }
    }

    /**
     * What refresh() collected: regions of this component to render
     * inline, and hints for other components.
     *
     * @internal
     *
     * @return array{regions: list<string>, hints: list<array{component: string, region?: string}>}
     */
    final public function asgardRefreshes(): array
    {
        return [
            'regions' => array_keys($this->asgardInline),
            'hints' => array_values($this->asgardHints),
        ];
    }

    /** The region being rendered. */
    final protected function region(): string
    {
        return $this->asgardRegion;
    }

    /**
     * Render every region in this answer, even ones the client holds a
     * fresh copy of, and tell the client to replace its copies. Covers
     * the current answer only. Call it from a #[ServerAction] or
     * #[Upload] method that changes what a region shows, from
     * checkClientCache(), or from a region method before it returns.
     */
    final protected function forceCacheInvalidation(): void
    {
        $this->asgardInvalidate = true;
    }

    /**
     * Whether the client sent this render because a push told it to. The
     * server does nothing with it by itself. To make a push always win over
     * a fresh client copy:
     *
     *     protected function checkClientCache(string $region, ClientCache $cache): void
     *     {
     *         if ($this->fetchedForPush()) {
     *             $this->forceCacheInvalidation();
     *         }
     *     }
     *
     * A client could claim this falsely. The worst that gets it is a
     * fresh render. Calls and uploads never carry it.
     */
    final protected function fetchedForPush(): bool
    {
        return $this->asgardPush;
    }

    /** @internal */
    final public function setAsgardPush(bool $push): void
    {
        $this->asgardPush = $push;
    }

    /** The copy of this region the client said it holds, or null for none. */
    final protected function clientCache(string $region): ?ClientCache
    {
        return $this->asgardClientCache[$region] ?? null;
    }

    /**
     * Called for each region about to be rendered whose client copy is
     * fresh, in its grace window or not, before the server decides whether
     * to skip rendering it. Compare
     * $cache->madeAt with when the data last changed, and call
     * forceCacheInvalidation() when the copy is out of date:
     *
     *     protected function checkClientCache(string $region, ClientCache $cache): void
     *     {
     *         if (Post::find($this->id)?->updated_at > $cache->madeAt) {
     *             $this->forceCacheInvalidation();
     *         }
     *     }
     */
    protected function checkClientCache(string $region, ClientCache $cache): void {}

    /**
     * @internal
     *
     * @param  array<string, ClientCache>  $caches
     */
    final public function setAsgardClientCache(array $caches): void
    {
        $this->asgardClientCache = $caches;
    }

    /**
     * Whether this region can go back as `{"cached": true}`: the client
     * declared a copy in this request, it is fresh and out of its grace
     * window, checkClientCache() let it stand and nothing forced
     * invalidation.
     *
     * @internal
     */
    final public function asgardServesFromCache(string $region): bool
    {
        $cache = $this->asgardClientCache[$region] ?? null;

        if ($this->asgardInvalidate || $cache === null || ! $cache->isFresh()) {
            return false;
        }

        $this->setAsgardContext($region);
        $this->checkClientCache($region, $cache);

        // A copy about to run out is rendered as a normal fragment, so the
        // client gets a new one in time. The hook still ran for it.
        return ! $this->asgardInvalidate && ! $cache->inGrace();
    }

    /** @internal */
    final public function asgardInvalidatesCache(): bool
    {
        return $this->asgardInvalidate;
    }
}
