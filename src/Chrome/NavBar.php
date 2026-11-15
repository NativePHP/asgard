<?php

namespace Native\Asgard\Chrome;

use Native\Asgard\Node;
use Native\Asgard\Support\Callback;

/**
 * The top bar of a server screen. Covers the device's NavBar builder and
 * NavBarOptions in one, since a server screen has no layout to merge into.
 */
class NavBar
{
    /** @var array<string, mixed> */
    protected array $bar = [];

    /** @var list<NavAction> */
    protected array $actions = [];

    protected Node|array|null $titleView = null;

    public static function make(): static
    {
        return new static;
    }

    /** Keep the bar's config but hide it on this screen, like $hidesNavBar. */
    public function hidden(bool $hidden = true): static
    {
        return $this->set('hidden', $hidden);
    }

    public function title(?string $title): static
    {
        return $this->set('title', $title);
    }

    public function subtitle(?string $subtitle): static
    {
        return $this->set('subtitle', $subtitle);
    }

    public function back(bool $show = true): static
    {
        return $this->set('back', $show);
    }

    /** An image in place of the title, like NavBar::logo(). */
    public function logo(string $src, float $height = 28): static
    {
        return $this->set('logo', ['src' => $src, 'height' => $height]);
    }

    /** Any content in place of the title, like NavBar::titleView(). */
    public function titleView(Node|array|null $content): static
    {
        $this->titleView = $content;

        return $this;
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

    public function elevation(int $px): static
    {
        return $this->set('elevation', $px);
    }

    /** `large`, `inline` or `automatic`. */
    public function displayMode(string $mode): static
    {
        return $this->set('display_mode', $mode);
    }

    /** `collapse`, `pinned` or `enterAlways`. */
    public function scrollBehavior(string $mode): static
    {
        return $this->set('scroll_behavior', $mode);
    }

    /**
     * A search field in the bar. With $onQuery, each (debounced) query runs
     * that #[ServerAction] on the server with the text appended to its args.
     */
    public function searchBar(string $placeholder = '', ?string $onQuery = null, int $debounceMs = 300): static
    {
        $search = ['placeholder' => $placeholder, 'debounce_ms' => $debounceMs];
        if ($onQuery !== null) {
            $search['on_query'] = Callback::call($onQuery, []);
        }

        return $this->set('search', $search);
    }

    public function action(NavAction $action): static
    {
        $this->actions[] = $action;

        return $this;
    }

    protected function set(string $key, mixed $value): static
    {
        if ($value === null) {
            unset($this->bar[$key]);
        } else {
            $this->bar[$key] = $value;
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $bar = $this->bar;

        if ($this->titleView !== null) {
            $bar['title_view'] = Chrome::content($this->titleView);
        }
        if ($this->actions !== []) {
            $bar['actions'] = array_map(fn (NavAction $action) => $action->toArray(), $this->actions);
        }

        return $bar;
    }
}
