<?php

namespace Native\Asgard\Chrome;

/**
 * The bottom tab bar of a server screen. Covers the device's TabBar
 * builder and TabBarOptions in one.
 */
class TabBar
{
    /** @var array<string, mixed> */
    protected array $bar = [];

    /** @var list<Tab> */
    protected array $tabs = [];

    public static function make(): static
    {
        return new static;
    }

    public function add(Tab $tab): static
    {
        $this->tabs[] = $tab;

        return $this;
    }

    /** Keep the bar's config but hide it on this screen, like $hidesTabBar. */
    public function hidden(bool $hidden = true): static
    {
        return $this->set('hidden', $hidden);
    }

    /** Show this tab id as the active one, whatever the route. */
    public function highlight(string $tabId): static
    {
        return $this->set('highlight', $tabId);
    }

    public function activeColor(string $color): static
    {
        return $this->set('active_color', $color);
    }

    public function backgroundColor(string $color): static
    {
        return $this->set('background_color', $color);
    }

    public function textColor(string $color): static
    {
        return $this->set('text_color', $color);
    }

    public function font(string $font): static
    {
        return $this->set('font', $font);
    }

    /** `labeled`, `selected` or `unlabeled`. */
    public function labelVisibility(string $mode): static
    {
        return $this->set('label_visibility', $mode);
    }

    public function dark(bool $dark = true): static
    {
        return $this->set('dark', $dark);
    }

    public function minimizeOnScroll(bool $minimize = true): static
    {
        return $this->set('minimize_on_scroll', $minimize);
    }

    /** A plugin-provided tab bar presenter, like TabBar::presentation(). */
    public function presentation(string $id, array $configuration = []): static
    {
        $presentation = ['id' => $id];
        if ($configuration !== []) {
            $presentation['configuration'] = $configuration;
        }

        return $this->set('presentation', $presentation);
    }

    protected function set(string $key, mixed $value): static
    {
        $this->bar[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $bar = $this->bar;

        if ($this->tabs !== []) {
            $bar['tabs'] = array_map(fn (Tab $tab) => $tab->toArray(), $this->tabs);
        }

        return $bar;
    }
}
