<?php

namespace Native\Asgard\Support;

use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;

/**
 * Turns whatever a region method returned into a clean §9.1 tree, or throws.
 *
 * Rejects screen chrome and nested regions (§9.3), unknown node keys and
 * non-plain values. Strips props whose keys start with `_`, `native-` or
 * `native:`.
 */
final class FragmentValidator
{
    public const DENIED_TYPES = [
        'top_bar', 'top_bar_action', 'top_bar_title',
        'bottom_nav', 'bottom_nav_item', 'bottom_bar',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
        'fab', 'server_region',
    ];

    public const CALLBACKS = ['on_press', 'on_long_press', 'on_double_tap', 'on_change', 'on_submit'];

    private const MAX_DEPTH = 64;

    /**
     * @return array<string, mixed>|list<array<string, mixed>> one node, or a list of nodes
     */
    public static function validate(mixed $fragment): array
    {
        if ($fragment instanceof Node) {
            return self::node($fragment->toArray(), 'fragment', 0);
        }

        if (is_array($fragment) && array_is_list($fragment)) {
            return array_map(
                fn ($child, $i) => self::node($child, "fragment[{$i}]", 0),
                $fragment,
                array_keys($fragment),
            );
        }

        return self::node($fragment, 'fragment', 0);
    }

    /** @return array<string, mixed> */
    private static function node(mixed $node, string $path, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidFragment("{$path}: fragment is nested deeper than ".self::MAX_DEPTH.' levels.');
        }

        if ($node instanceof Node) {
            $node = $node->toArray();
        }

        if (! is_array($node) || ($node !== [] && array_is_list($node))) {
            throw new InvalidFragment("{$path}: expected a node (Node or array with a 'type'), got ".get_debug_type($node).'.');
        }

        $type = $node['type'] ?? null;
        if (! is_string($type) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $type)) {
            throw new InvalidFragment("{$path}: 'type' must be a snake_case element name.");
        }
        if (in_array($type, self::DENIED_TYPES, true)) {
            throw new InvalidFragment("{$path}: element type [{$type}] is not allowed in a fragment. Regions cannot contain screen chrome or other regions.");
        }

        $clean = ['type' => $type];

        foreach ($node as $field => $value) {
            switch (true) {
                case $field === 'type':
                    break;

                case $field === 'key':
                    if (! is_string($value) && ! is_int($value)) {
                        throw new InvalidFragment("{$path}: 'key' must be a string or int.");
                    }
                    $clean['key'] = $value;
                    break;

                case $field === 'class':
                    if (! is_string($value)) {
                        throw new InvalidFragment("{$path}: 'class' must be a string.");
                    }
                    $clean['class'] = $value;
                    break;

                case $field === 'props':
                    $clean['props'] = self::props($value, $path);
                    if ($clean['props'] === []) {
                        unset($clean['props']);
                    }
                    break;

                case in_array($field, self::CALLBACKS, true):
                    $clean[$field] = Callback::validateSlot($value, "{$path}.{$field}", $field);
                    break;

                case $field === 'children':
                    if (! is_array($value) || ! array_is_list($value)) {
                        throw new InvalidFragment("{$path}: 'children' must be a list of nodes.");
                    }
                    $children = [];
                    foreach ($value as $i => $child) {
                        $children[] = self::node($child, "{$path}.children[{$i}]", $depth + 1);
                    }
                    if ($children !== []) {
                        $clean['children'] = $children;
                    }
                    break;

                default:
                    throw new InvalidFragment("{$path}: unknown node field [{$field}].");
            }
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    private static function props(mixed $props, string $path): array
    {
        if (! is_array($props) || ($props !== [] && array_is_list($props))) {
            throw new InvalidFragment("{$path}: 'props' must be an object.");
        }

        $clean = [];
        foreach ($props as $name => $value) {
            $name = (string) $name;

            if (str_starts_with($name, '_') || str_starts_with($name, 'native-') || str_starts_with($name, 'native:')) {
                continue;
            }

            if (! self::isPlain($value, 0)) {
                throw new InvalidFragment("{$path}: prop [{$name}] must be a scalar, null or an array of those.");
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    private static function isPlain(mixed $value, int $depth): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (! is_array($value) || $depth >= self::MAX_DEPTH) {
            return false;
        }
        foreach ($value as $item) {
            if (! self::isPlain($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
