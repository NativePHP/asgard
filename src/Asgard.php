<?php

namespace Native\Asgard;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use InvalidArgumentException;
use Native\Asgard\Support\Channels;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\PushVersions;

/**
 * What the Asgard facade points at: the component and screen registries,
 * plus push(), patch() and to().
 */
class Asgard
{
    public function __construct(
        protected ComponentRegistry $registry,
        protected PushVersions $versions,
        protected ScreenRegistry $screens,
    ) {}

    /**
     * Register a server screen. Its route comes from its #[Screen]
     * attribute, and it is also registered as a component at its address.
     *
     * @param  class-string<ServerScreen>  $class
     */
    public function screen(string $class): ScreenRegistry
    {
        return $this->screens->screen($class);
    }

    /** @return list<array{uri: string, screen: string}> */
    public function routes(): array
    {
        return $this->screens->routes();
    }

    /** @param class-string<ServerComponent> $class */
    public function component(string $address, string $class): ComponentRegistry
    {
        return $this->registry->component($address, $class);
    }

    /** @return class-string<ServerComponent>|null */
    public function resolve(string $address): ?string
    {
        return $this->registry->resolve($address);
    }

    /** @return array<string, class-string<ServerComponent>> */
    public function all(): array
    {
        return $this->registry->all();
    }

    /**
     * Tell every open stream watching a component to re-render it. With a
     * region, only subscriptions to that region re-render it.
     *
     * Takes a registered address or a registered class. A class registered
     * under several addresses is pushed to all of them. Asgard::to() pushes
     * to the subscriptions in some channels only.
     */
    public function push(string $component, ?string $region = null): void
    {
        foreach ($this->addresses($component, $region) as $address) {
            $this->versions->bump($address, $region);
        }
    }

    /**
     * Change one list inside a region on every open stream watching it,
     * without re-sending the region:
     *
     *     Asgard::patch(Firehose::class, 'feed')->prepend($node, into: 'items', keep: 1000);
     *
     * Each operation is sent at once, as one patch, and bumps the region's
     * version as push() does. The region may be left out for a server
     * screen or a component with one region. Asgard::to()->patch() sends
     * to some channels only.
     */
    public function patch(string $component, ?string $region = null): PendingPatch
    {
        [$addresses, $region, $class] = $this->patchTarget($component, $region);

        return new PendingPatch(
            $this->versions,
            array_map(fn (string $address) => PushVersions::regionKey($address, $region), $addresses),
            $class,
            $region,
        );
    }

    /**
     * The addresses, region and class a patch reaches, checked as push()
     * checks them. Without a region, the component must have just one.
     *
     * @internal
     *
     * @return array{list<string>, string, class-string<ServerComponent>}
     */
    public function patchTarget(string $component, ?string $region): array
    {
        $addresses = $this->addresses($component);
        $components = $this->registry->all();

        if ($region === null) {
            $regions = array_keys(ComponentMetadata::for($components[$addresses[0]])->regions);

            if (count($regions) !== 1) {
                throw new InvalidArgumentException("A patch for [{$components[$addresses[0]]}] needs a region: it has ".count($regions).'.');
            }

            $region = (string) $regions[0];
        }

        return [$this->addresses($component, $region), $region, $components[$addresses[0]]];
    }

    /**
     * Push to the subscriptions that joined these channels, through their
     * component's channels(). Takes channel names, models (named the way
     * Laravel broadcasting names them, App.Models.User.5) or lists of them.
     *
     *     Asgard::to('promo.42')->push(PromoScreen::class, 'screen');
     *     Asgard::to($user)->push();
     *
     * @param  string|HasBroadcastChannel|iterable<string|HasBroadcastChannel>  ...$channels
     */
    public function to(mixed ...$channels): PendingPush
    {
        $names = Channels::names($channels);

        if ($names === []) {
            throw new InvalidArgumentException('Asgard::to() needs at least one channel.');
        }

        return new PendingPush($this, $this->versions, $names);
    }

    /**
     * The addresses a push for this component reaches, after checking the
     * component is registered and the region is a #[Region] on it.
     *
     * @internal
     *
     * @return list<string>
     */
    public function addresses(string $component, ?string $region = null): array
    {
        $components = $this->registry->all();

        $addresses = isset($components[$component])
            ? [$component]
            : array_keys($components, ltrim($component, '\\'), true);

        if ($addresses === []) {
            throw new InvalidArgumentException("[{$component}] is not a registered Asgard component address or class.");
        }

        if ($region !== null) {
            foreach ($addresses as $address) {
                if (! isset(ComponentMetadata::for($components[$address])->regions[$region])) {
                    throw new InvalidArgumentException("[{$region}] is not a #[Region] on [{$components[$address]}].");
                }
            }
        }

        return $addresses;
    }
}
