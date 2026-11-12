<?php

namespace Native\Asgard\Chrome;

use JsonSerializable;
use Native\Asgard\Node;

/**
 * A server screen's chrome: everything a NativeLayout and a screen's
 * navigationOptions() / tabBarOptions() describe in an app, sent as its
 * own part of the answer (protocol §12, "Chrome").
 *
 *     Chrome::make()
 *         ->native()
 *         ->navBar(NavBar::make()->title('Promo')->back()
 *             ->action(NavAction::make('share')->icon('share')->onPress('share')))
 *         ->tabBar(TabBar::make()->hidden());
 */
class Chrome implements JsonSerializable
{
    protected bool $native = false;

    protected ?string $font = null;

    protected ?NavBar $navBar = null;

    protected ?TabBar $tabBar = null;

    /** @var Node|array<mixed>|null */
    protected Node|array|null $bottomBar = null;

    /** @var Node|array<mixed>|null */
    protected Node|array|null $tabAccessory = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * Draw the bars with the platform's own navigation (NavigationStack /
     * TabView, NavHost / Scaffold), like NativeLayout::usesNativeChrome().
     */
    public function native(bool $native = true): static
    {
        $this->native = $native;

        return $this;
    }

    /** A resources/fonts token for both bars, like a layout's $font. */
    public function font(?string $font): static
    {
        $this->font = $font;

        return $this;
    }

    public function navBar(?NavBar $navBar): static
    {
        $this->navBar = $navBar;

        return $this;
    }

    public function tabBar(?TabBar $tabBar): static
    {
        $this->tabBar = $tabBar;

        return $this;
    }

    /** Content pinned to the bottom above the keyboard, like NativeLayout::bottomBar(). Native chrome only. */
    public function bottomBar(Node|array|null $content): static
    {
        $this->bottomBar = $content;

        return $this;
    }

    /** Content pinned above the tab bar, like NativeLayout::tabBarAccessory(). Native chrome only. */
    public function tabAccessory(Node|array|null $content): static
    {
        $this->tabAccessory = $content;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $chrome = [];

        if ($this->native) {
            $chrome['native'] = true;
        }
        if ($this->font !== null) {
            $chrome['font'] = $this->font;
        }
        if ($this->navBar !== null) {
            $chrome['nav_bar'] = $this->navBar->toArray();
        }
        if ($this->tabBar !== null) {
            $chrome['tab_bar'] = $this->tabBar->toArray();
        }
        if ($this->bottomBar !== null) {
            $chrome['bottom_bar'] = self::content($this->bottomBar);
        }
        if ($this->tabAccessory !== null) {
            $chrome['tab_accessory'] = self::content($this->tabAccessory);
        }

        return $chrome;
    }

    /**
     * A content slot as plain arrays: one node, or a list of them.
     *
     * @internal
     */
    public static function content(Node|array $content): array
    {
        if ($content instanceof Node) {
            return $content->toArray();
        }

        return array_is_list($content)
            ? array_map(fn ($node) => $node instanceof Node ? $node->toArray() : $node, $content)
            : $content;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
