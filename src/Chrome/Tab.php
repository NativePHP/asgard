<?php

namespace Native\Asgard\Chrome;

/**
 * One tab, like the device's Tab builder. A link tab opens its route when
 * tapped (the device checks the route exists). A press slot overrides that.
 */
class Tab
{
    use HasIcon;
    use HasSlots;

    protected string $id;

    protected ?string $url = null;

    protected ?string $badge = null;

    protected ?string $badgeColor = null;

    protected bool $news = false;

    protected bool $active = false;

    /** @var array{placeholder?: string, debounce_ms: int}|null */
    protected ?array $search = null;

    final public function __construct(protected string $label)
    {
        $this->id = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $label));
    }

    public static function link(string $label, string $url, ?string $icon = null, ?string $ios = null, ?string $android = null): static
    {
        $tab = new static($label);
        $tab->url = $url;

        return $tab->icon($icon, $ios, $android);
    }

    public static function action(string $label, ?string $icon = null, ?string $ios = null, ?string $android = null): static
    {
        return (new static($label))->icon($icon, $ios, $android);
    }

    /** The iOS search-role tab. */
    public static function search(string $label, ?string $icon = null, ?string $placeholder = null, int $debounceMs = 250): static
    {
        $tab = (new static($label))->icon($icon);
        $tab->search = array_filter(['placeholder' => $placeholder, 'debounce_ms' => $debounceMs], fn ($v) => $v !== null);

        return $tab;
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function badge(string $badge, ?string $color = null): static
    {
        $this->badge = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    public function news(bool $news = true): static
    {
        $this->news = $news;

        return $this;
    }

    public function active(bool $active = true): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tab = ['id' => $this->id, 'label' => $this->label, ...$this->iconArray()];

        if ($this->url !== null) {
            $tab['url'] = $this->url;
        }
        if ($this->badge !== null) {
            $tab['badge'] = $this->badge;
        }
        if ($this->badgeColor !== null) {
            $tab['badge_color'] = $this->badgeColor;
        }
        if ($this->news) {
            $tab['news'] = true;
        }
        if ($this->active) {
            $tab['active'] = true;
        }
        if ($this->search !== null) {
            $tab['search'] = $this->search;
        }
        if ($this->onPress !== null) {
            $tab['on_press'] = $this->onPress;
        }

        return $tab;
    }
}
