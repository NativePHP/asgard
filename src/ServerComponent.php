<?php

namespace Native\Asgard;

/**
 * Base class for the server half of a component.
 *
 * Public, non-static, typed properties declared on your subclass are client
 * state: they are hydrated from the request before a region method runs.
 * Mark a property #[Locked] to keep the client from writing it.
 *
 * Public methods marked #[Region('name')] render regions.
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
