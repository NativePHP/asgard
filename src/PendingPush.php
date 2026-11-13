<?php

namespace Native\Asgard;

use InvalidArgumentException;
use Native\Asgard\Support\PushVersions;

/**
 * A push aimed at some channels, from Asgard::to(). Only stream
 * subscriptions that joined one of the channels re-render, and each
 * re-renders with its own state and its stream's user, as with any push.
 */
class PendingPush
{
    /** @param list<string> $channels */
    public function __construct(
        protected Asgard $asgard,
        protected PushVersions $versions,
        protected array $channels,
    ) {}

    /**
     * With no component, every subscription in the channels re-renders,
     * whatever its component. With a component, only that component's do,
     * and with a region as well, only that region.
     *
     * The component and region are checked as Asgard::push() checks them.
     */
    public function push(?string $component = null, ?string $region = null): void
    {
        if ($component === null) {
            if ($region !== null) {
                throw new InvalidArgumentException('A channel push with a region needs a component too.');
            }

            foreach ($this->channels as $channel) {
                $this->versions->bumpChannel($channel);
            }

            return;
        }

        $addresses = $this->asgard->addresses($component, $region);

        foreach ($this->channels as $channel) {
            foreach ($addresses as $address) {
                $this->versions->bumpChannel($channel, $address, $region);
            }
        }
    }

    /** @return list<string> */
    public function channels(): array
    {
        return $this->channels;
    }
}
