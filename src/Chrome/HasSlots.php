<?php

namespace Native\Asgard\Chrome;

use Native\Asgard\Support\Callback;

/**
 * The press slot of a chrome button, with the same builders as Node and
 * the same checked shapes (Asgard protocol §10.1, §12.4).
 */
trait HasSlots
{
    /** @var array<string, mixed>|null */
    protected ?array $onPress = null;

    /** Runs the screen's #[Action] method on the server, then re-renders the screen. */
    public function onPress(string $method, mixed ...$args): static
    {
        $this->onPress = Callback::call($method, $args);

        return $this;
    }

    /** Runs a #[NativeAction] method on the device's screen. */
    public function onPressClient(string $method, mixed ...$args): static
    {
        $this->onPress = Callback::call($method, $args, 'client');

        return $this;
    }

    /** Moves the app to a route the device knows. */
    public function onPressNavigate(string $uri, bool $replace = false, ?string $transition = null): static
    {
        $this->onPress = Callback::navigate($uri, $replace, $transition);

        return $this;
    }

    public function onPressBack(?string $transition = null): static
    {
        $this->onPress = Callback::back($transition);

        return $this;
    }
}
