<?php

namespace Native\Asgard;

use InvalidArgumentException;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\PushVersions;

/**
 * What the Asgard facade points at: the component and screen registries, plus push.
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
     * Tell open streams for a component to re-render. With a region, only
     * streams subscribed to that region re-render it.
     *
     * Takes a registered address or a registered class. A class registered
     * under several addresses is pushed to all of them.
     */
    public function push(string $component, ?string $region = null): void
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

        foreach ($addresses as $address) {
            $this->versions->bump($address, $region);
        }
    }
}
