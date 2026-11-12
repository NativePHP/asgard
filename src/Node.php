<?php

namespace Native\Asgard;

use JsonSerializable;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Support\Callback;

/**
 * Fluent builder for one fragment node (protocol §9.1).
 *
 *     Node::column(
 *         Node::text('Hello'),
 *         Node::make('button')->props(['label' => 'Go'])->onPress('go'),
 *     )->class('p-4 gap-2');
 */
class Node implements JsonSerializable
{
    protected string|int|null $key = null;

    /** @var array<string, mixed> */
    protected array $props = [];

    protected ?string $class = null;

    /** @var array<string, array{call: string, args: list<mixed>, target?: 'client'}> */
    protected array $callbacks = [];

    /** @var list<Node|array<string, mixed>> */
    protected array $children = [];

    final public function __construct(protected string $type) {}

    public static function make(string $type): static
    {
        return new static($type);
    }

    public static function column(Node|array ...$children): static
    {
        return static::make('column')->children(...$children);
    }

    public static function row(Node|array ...$children): static
    {
        return static::make('row')->children(...$children);
    }

    public static function text(string $text): static
    {
        return static::make('text')->props(['text' => $text]);
    }

    public static function button(string $label): static
    {
        return static::make('button')->props(['label' => $label]);
    }

    public static function spacer(): static
    {
        return static::make('spacer');
    }

    public static function divider(): static
    {
        return static::make('divider');
    }

    public function key(string|int $key): static
    {
        $this->key = $key;

        return $this;
    }

    /** Merges into the existing props. */
    public function props(array $props): static
    {
        $this->props = array_merge($this->props, $props);

        return $this;
    }

    public function prop(string $name, mixed $value): static
    {
        $this->props[$name] = $value;

        return $this;
    }

    public function class(string $class): static
    {
        $this->class = $class;

        return $this;
    }

    /** Appends children. Accepts nodes, node arrays, or lists of either. */
    public function children(Node|array ...$children): static
    {
        foreach ($children as $child) {
            if (is_array($child) && array_is_list($child)) {
                array_push($this->children, ...$child);
            } else {
                $this->children[] = $child;
            }
        }

        return $this;
    }

    /** Runs the #[Action] method on the server, then re-renders the region. */
    public function onPress(string $method, mixed ...$args): static
    {
        return $this->on('on_press', $method, $args, 'server');
    }

    /** Runs a client-side action on the device. */
    public function onPressClient(string $method, mixed ...$args): static
    {
        return $this->on('on_press', $method, $args, 'client');
    }

    public function onLongPress(string $method, mixed ...$args): static
    {
        return $this->on('on_long_press', $method, $args, 'server');
    }

    public function onLongPressClient(string $method, mixed ...$args): static
    {
        return $this->on('on_long_press', $method, $args, 'client');
    }

    public function onDoubleTap(string $method, mixed ...$args): static
    {
        return $this->on('on_double_tap', $method, $args, 'server');
    }

    public function onDoubleTapClient(string $method, mixed ...$args): static
    {
        return $this->on('on_double_tap', $method, $args, 'client');
    }

    public function onChange(string $method, mixed ...$args): static
    {
        return $this->on('on_change', $method, $args, 'server');
    }

    public function onChangeClient(string $method, mixed ...$args): static
    {
        return $this->on('on_change', $method, $args, 'client');
    }

    public function onSubmit(string $method, mixed ...$args): static
    {
        return $this->on('on_submit', $method, $args, 'server');
    }

    public function onSubmitClient(string $method, mixed ...$args): static
    {
        return $this->on('on_submit', $method, $args, 'client');
    }

    /** @param array<int|string, mixed> $args */
    protected function on(string $event, string $method, array $args, string $target): static
    {
        if ($problem = Callback::nameProblem($method) ?? Callback::argsProblem($args)) {
            throw new InvalidFragment("{$event}: {$problem}");
        }

        $callback = ['call' => $method, 'args' => $args];
        if ($target === 'client') {
            $callback['target'] = 'client';
        }

        $this->callbacks[$event] = $callback;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $node = ['type' => $this->type];

        if ($this->key !== null) {
            $node['key'] = $this->key;
        }
        if ($this->props !== []) {
            $node['props'] = $this->props;
        }
        if ($this->class !== null && $this->class !== '') {
            $node['class'] = $this->class;
        }

        $node += $this->callbacks;

        if ($this->children !== []) {
            $node['children'] = array_map(
                fn ($child) => $child instanceof Node ? $child->toArray() : $child,
                $this->children,
            );
        }

        return $node;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
