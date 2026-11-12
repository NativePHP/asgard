<?php

namespace Native\Asgard\Chrome;

/**
 * A button in the top bar, or an item in its menu, like the device's
 * NavAction. Its press is a checked slot: a server action, a client
 * action, or a navigation.
 */
class NavAction
{
    use HasIcon;
    use HasSlots;

    protected ?string $label = null;

    protected ?string $a11yLabel = null;

    protected ?string $image = null;

    protected bool $destructive = false;

    protected bool $disabled = false;

    protected bool $divider = false;

    /** @var list<NavAction> */
    protected array $items = [];

    final public function __construct(protected string $id) {}

    public static function make(string $id): static
    {
        return new static($id);
    }

    /** A separator line inside items(). */
    public static function divider(): static
    {
        $divider = new static('divider');
        $divider->divider = true;

        return $divider;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function a11yLabel(string $label): static
    {
        $this->a11yLabel = $label;

        return $this;
    }

    /** A remote image (an avatar, say) in place of the icon. */
    public function image(?string $url): static
    {
        $this->image = $url === null || trim($url) === '' ? null : $url;

        return $this;
    }

    public function destructive(bool $destructive = true): static
    {
        $this->destructive = $destructive;

        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;

        return $this;
    }

    /** @param list<NavAction> $items Turns the button into a pull-down menu. */
    public function items(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->divider) {
            return ['id' => $this->id, 'divider' => true];
        }

        $action = ['id' => $this->id, ...$this->iconArray()];

        foreach (['label' => $this->label, 'a11y_label' => $this->a11yLabel, 'image' => $this->image] as $key => $value) {
            if ($value !== null) {
                $action[$key] = $value;
            }
        }
        if ($this->destructive) {
            $action['destructive'] = true;
        }
        if ($this->disabled) {
            $action['disabled'] = true;
        }
        if ($this->onPress !== null) {
            $action['on_press'] = $this->onPress;
        }
        if ($this->items !== []) {
            $action['items'] = array_map(fn (NavAction $item) => $item->toArray(), $this->items);
        }

        return $action;
    }
}
