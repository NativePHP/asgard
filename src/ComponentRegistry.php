<?php

namespace Native\Asgard;

use InvalidArgumentException;

/**
 * The only route from a wire address to a class. Lookups are plain array
 * reads against a map the developer wrote; a wire string is never treated
 * as a class name.
 */
class ComponentRegistry
{
    /** Dot-joined segments, each starting with a letter (Asgard protocol §4). */
    public const ADDRESS_PATTERN = '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*$/D';

    /** @var array<string, class-string<ServerComponent>> */
    protected array $components = [];

    /** @param array<string, class-string<ServerComponent>> $components */
    public function __construct(array $components = [])
    {
        foreach ($components as $address => $class) {
            $this->component($address, $class);
        }
    }

    /** @param class-string<ServerComponent> $class */
    public function component(string $address, string $class): static
    {
        if (! preg_match(self::ADDRESS_PATTERN, $address)) {
            throw new InvalidArgumentException("Asgard address [{$address}] must be dot notation, each segment a lower-case letter followed by letters, digits, underscores or hyphens.");
        }

        // Developer-authored input, so checking the class here is fine.
        if (! is_subclass_of($class, ServerComponent::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".ServerComponent::class.'.');
        }

        $this->components[$address] = $class;

        return $this;
    }

    /** @return class-string<ServerComponent>|null */
    public function resolve(string $address): ?string
    {
        return $this->components[$address] ?? null;
    }

    /** @return array<string, class-string<ServerComponent>> */
    public function all(): array
    {
        return $this->components;
    }
}
