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
 * channels() names the channels an open stream joins, so Asgard::to() can
 * push to it.
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
     * The channels a stream for this component joins when it opens, for
     * Asgard::to() pushes. Called once per stream, on an instance hydrated
     * from the stream's state, after the app's middleware, so auth() works:
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
     * (App.Models.User.5). Repeats are dropped, and a stream joins at most
     * 16. Only the server works these out; the device never sends them.
     *
     * This is the authorization step. Anyone with the app's key can open a
     * stream with any state, so a channel built from state ("promo.{$id}")
     * is only as private as that state. Build a private channel from the
     * authenticated user, with auth middleware in asgard.middleware, and
     * check auth()->check() first. Render code still authorizes what it
     * shows, since a push re-renders with the stream's own state and user.
     *
     * region() is '' here, because a stream covers several regions.
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
