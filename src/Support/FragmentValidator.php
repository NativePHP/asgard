<?php

namespace Native\Asgard\Support;

use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;

/**
 * Turns whatever a region method returned into a clean fragment, or throws
 * (Asgard protocol §9).
 *
 * A node may only use a core type (§9.7) or one the client listed in
 * `elements` at the handshake (§6.1). Screen chrome and regions are never
 * allowed, even when listed (§9.3). Refuses nesting deeper than 64 levels
 * (§9.1), siblings that share a key, a text node with other kinds of
 * children, non-plain values and a web view that would load the app's own
 * content. Node members it does not know are dropped (§5.3). Strips props
 * whose keys start with `_`, `native-` or `native:`. Makes media URLs
 * absolute (MediaUrls, §9.6).
 */
final class FragmentValidator
{
    /** The types every client renders (§9.7). */
    public const CORE_TYPES = ['column', 'row', 'stack', 'scroll_view', 'text', 'button', 'image', 'spacer', 'divider'];

    /**
     * Screen chrome and regions (§9.3), by the names NativePHP's elements
     * use. Never allowed in a fragment, even when a client lists one.
     */
    public const NEVER_TYPES = [
        'top_bar', 'top_bar_action', 'top_bar_title',
        'bottom_nav', 'bottom_nav_item', 'bottom_bar',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
        'native_drawer', 'fab', 'server_region',
    ];

    public const CALLBACKS = ['on_press', 'on_long_press', 'on_double_tap', 'on_change', 'on_submit'];

    /** The deepest a fragment may nest. The root is at depth 1 (§9.1). */
    public const MAX_DEPTH = 64;

    /**
     * @param  list<string>  $elements  the extra types the client advertised
     * @return array<string, mixed>|list<array<string, mixed>> one node, or a list of nodes
     */
    public static function validate(mixed $fragment, array $elements = []): array
    {
        $allowed = array_fill_keys(array_diff([...self::CORE_TYPES, ...$elements], self::NEVER_TYPES), true);

        if ($fragment instanceof Node) {
            return self::node($fragment->toArray(), 'fragment', 1, $allowed);
        }

        if (is_array($fragment) && array_is_list($fragment)) {
            return self::siblings(array_map(
                fn ($child, $i) => self::node($child, "fragment[{$i}]", 1, $allowed),
                $fragment,
                array_keys($fragment),
            ), 'fragment');
        }

        return self::node($fragment, 'fragment', 1, $allowed);
    }

    /**
     * @param  array<string, true>  $allowed
     * @return array<string, mixed>
     */
    private static function node(mixed $node, string $path, int $depth, array $allowed): array
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
        if (in_array($type, self::NEVER_TYPES, true)) {
            throw new InvalidFragment("{$path}: element type [{$type}] is not allowed in a fragment. Regions cannot contain screen chrome or other regions.");
        }
        if (! isset($allowed[$type])) {
            throw new InvalidFragment("{$path}: element type [{$type}] is not a core type and the client did not list it in elements.");
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
                    $clean['props'] = self::props($value, $path, $type);
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
                        $children[] = self::node($child, "{$path}.children[{$i}]", $depth + 1, $allowed);
                    }
                    // A text node's children are inline runs of text (§9.7).
                    if ($type === 'text' && array_diff(array_column($children, 'type'), ['text']) !== []) {
                        throw new InvalidFragment("{$path}: a text node's children must be text nodes.");
                    }
                    $children = self::siblings($children, "{$path}.children");
                    if ($children !== []) {
                        $clean['children'] = $children;
                    }
                    break;

                default:
                    // A member this version does not know is ignored (§5.3),
                    // and so is not sent on.
                    break;
            }
        }

        return $clean;
    }

    /**
     * Siblings must not share a key, and keys compare as strings, so 7 and
     * "7" are the same key (§9.1).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function siblings(array $nodes, string $path): array
    {
        $seen = [];
        foreach ($nodes as $node) {
            if (! array_key_exists('key', $node)) {
                continue;
            }

            $key = (string) $node['key'];
            if (isset($seen[$key])) {
                throw new InvalidFragment("{$path}: two siblings share the key [{$key}].");
            }
            $seen[$key] = true;
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    private static function props(mixed $props, string $path, string $type): array
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

            $clean[$name] = in_array($name, MediaUrls::PROPS, true)
                ? MediaUrls::resolve($value, "{$path}.props.{$name}")
                : $value;
        }

        // A web view that loads the app's own content is the app's to open,
        // not a server's (§9.3). `local` turns that on. Older clients read
        // `php`, so it is refused the same way.
        if ($type === 'webview') {
            foreach (['local', 'php'] as $prop) {
                if (($clean[$prop] ?? false) !== false) {
                    throw new InvalidFragment("{$path}: a webview in a fragment cannot load the app's own content ([{$prop}] must be false or absent).");
                }
            }
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
