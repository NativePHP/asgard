<?php

namespace Native\Asgard;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;

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

    private string $asgardPlatform = '';

    /** @internal */
    final public function setAsgardContext(string $region, string $platform): void
    {
        $this->asgardRegion = $region;
        $this->asgardPlatform = $platform;
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

    /** The region being rendered. */
    final protected function region(): string
    {
        return $this->asgardRegion;
    }

    /** 'ios' or 'android'. */
    final protected function platform(): string
    {
        return $this->asgardPlatform;
    }
}
