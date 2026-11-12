<?php

namespace Native\Asgard\Blade;

use Illuminate\Support\Arr;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;

/**
 * Builds asgard Nodes from a compiled region view.
 *
 * NativeTagPrecompiler turns tags into calls on this class. It has the same
 * static API as the app's NativeElementCollector (open, close, leaf,
 * textOpen, textClose, textLeaf), but it only builds the logical tree a
 * fragment needs. Layout, styles, icons and callback ids stay on the device.
 *
 * Attributes map like this:
 *
 * - `class` becomes the node's class. An array is joined the way
 *   `@class` joins one.
 * - `key` (or `native:key`) becomes the node's key.
 * - `@press="m(1)"` becomes on_press {call: m, args: [1]}, a server action.
 *   `@press.client="m"` adds target client. Same for longPress, doubleTap,
 *   change and submit (and the tap/longTap spellings).
 * - `@navigate="/path"` becomes on_press {navigate: "/path"}, and
 *   `@navigate.back` becomes on_press {back: true}. A press can do one
 *   thing, so an element with both `@navigate` and `@press` (or `@tap`)
 *   is refused. The device lets `@navigate` win silently; here that would
 *   drop a handler without a word.
 * - Every other attribute is a prop, under the name it was written with,
 *   because that is the attribute bag the device's collector reads.
 *
 * Any other event (`@swipe`, `@pressDown`, a child component `@event`)
 * has no fragment slot, so it throws rather than vanish.
 */
final class NodeCollector
{
    private const CALLBACKS = [
        '_press' => 'onPress',
        '_longPress' => 'onLongPress',
        '_doubleTap' => 'onDoubleTap',
        '_change' => 'onChange',
        '_submit' => 'onSubmit',
    ];

    private const DEFAULT_WHITESPACE = 'normal';

    /** @var list<Node> open containers */
    private static array $stack = [];

    /** @var list<Node> */
    private static array $roots = [];

    /** @var list<array{attrs: array<string, mixed>, children: list<array<string, mixed>>, whitespace: ?string}> */
    private static array $textFrames = [];

    /**
     * Start collecting. Returns the state it replaced, for end().
     *
     * @return array{0: list<Node>, 1: list<Node>, 2: list<array<string, mixed>>}
     */
    public static function begin(): array
    {
        $saved = [self::$stack, self::$roots, self::$textFrames];
        self::$stack = self::$roots = self::$textFrames = [];

        return $saved;
    }

    /**
     * Stop collecting, restore the state begin() replaced, and return the
     * root nodes the view built. Pass $check false after a failed render.
     *
     * @param  array{0: list<Node>, 1: list<Node>, 2: list<array<string, mixed>>}  $saved
     * @return list<Node>
     */
    public static function end(array $saved, bool $check = true): array
    {
        $roots = self::$roots;
        $unclosed = self::$stack !== [] || self::$textFrames !== [];

        // Output buffers a failed render left open are cleaned up by the
        // view engine's own exception handling, not here.
        [self::$stack, self::$roots, self::$textFrames] = $saved;

        if ($check && $unclosed) {
            throw new InvalidFragment('A region view left an element open. Check that every tag is closed.');
        }

        return $roots;
    }

    public static function open(string $type, array $attrs): void
    {
        self::$stack[] = self::node($type, $attrs);
    }

    public static function close(): void
    {
        $node = array_pop(self::$stack);

        if ($node === null) {
            throw new InvalidFragment('A region view closed a tag it never opened.');
        }

        self::attach($node);
    }

    public static function leaf(string $type, array $attrs): void
    {
        self::attach(self::node($type, $attrs));
    }

    // ── <text>, with nested runs ─────

    public static function textOpen(array $attrs): void
    {
        $inherited = null;

        if (self::$textFrames !== []) {
            self::captureRawRunIntoTopFrame();
            $inherited = end(self::$textFrames)['whitespace'];
        }

        self::$textFrames[] = [
            'attrs' => $attrs,
            'children' => [],
            'whitespace' => self::whitespaceMode($attrs) ?? $inherited,
        ];
        ob_start();
    }

    /**
     * Same shapes as the device: a <text> with nested <text> runs becomes a
     * text node with text children, otherwise a leaf with a `text` prop.
     */
    public static function textClose(): void
    {
        $buffer = ob_get_clean();
        $frame = array_pop(self::$textFrames);
        $isNested = self::$textFrames !== [];
        $mode = $frame['whitespace'];

        if ($frame['children'] !== []) {
            $tail = self::normalizeRunText($buffer, $mode);
            if ($tail !== '') {
                $frame['children'][] = ['attrs' => ['text' => $tail], 'children' => null];
            }
            $descriptor = ['attrs' => self::withText($frame['attrs'], $mode, false), 'children' => $frame['children']];
        } elseif ($isNested) {
            $attrs = self::withText($frame['attrs'], $mode, true);
            $text = self::normalizeRunText($buffer, $mode);
            if ($text !== '') {
                $attrs['text'] = $text;
            }
            $descriptor = ['attrs' => $attrs, 'children' => null];
        } else {
            $attrs = self::withText($frame['attrs'], $mode, false);
            $text = self::normalizeLeafText($buffer, $mode);
            if ($text !== '') {
                $attrs['text'] = $text;
            }
            $descriptor = ['attrs' => $attrs, 'children' => null];
        }

        if ($isNested) {
            self::$textFrames[count(self::$textFrames) - 1]['children'][] = $descriptor;
            ob_start();
        } else {
            self::attach(self::textNode($descriptor));
        }
    }

    public static function textLeaf(array $attrs): void
    {
        self::textOpen($attrs);
        self::textClose();
    }

    // ── internals ─────

    private static function attach(Node $node): void
    {
        if (self::$stack === []) {
            self::$roots[] = $node;
        } else {
            self::$stack[count(self::$stack) - 1]->children($node);
        }
    }

    /** @param array{attrs: array<string, mixed>, children: list<array<string, mixed>>|null} $descriptor */
    private static function textNode(array $descriptor): Node
    {
        $node = self::node('text', $descriptor['attrs']);

        foreach ($descriptor['children'] ?? [] as $child) {
            $node->children(self::textNode($child));
        }

        return $node;
    }

    private static function node(string $type, array $attrs): Node
    {
        $node = Node::make($type);
        $props = [];

        if (array_key_exists('_navigate', $attrs)) {
            if (array_key_exists('_press', $attrs) || array_key_exists('_press-client', $attrs)) {
                throw new InvalidFragment("<{$type}> has both @navigate and @press. A press does one thing: navigate, or call a method that navigates.");
            }
        }

        foreach ($attrs as $name => $value) {
            $name = (string) $name;

            if ($name === 'class') {
                $class = is_array($value) ? Arr::toCssClasses($value) : (string) $value;
                if ($class !== '') {
                    $node->class($class);
                }

                continue;
            }

            if ($name === 'key' || $name === 'native-key') {
                if ($value !== null && $value !== '') {
                    $node->key(is_int($value) ? $value : (string) $value);
                }

                continue;
            }

            if ($name === '_navigate') {
                self::navigate($node, $value);

                continue;
            }

            if (str_starts_with($name, '_')) {
                self::callback($node, $name, $value);

                continue;
            }

            $props[$name] = $value;
        }

        return $props === [] ? $node : $node->props($props);
    }

    private static function callback(Node $node, string $name, mixed $value): void
    {
        $client = str_ends_with($name, '-client');
        $slot = $client ? substr($name, 0, -7) : $name;
        $method = self::CALLBACKS[$slot] ?? null;

        if ($method === null) {
            $event = str_starts_with($slot, '_event-') ? substr($slot, 7) : substr($slot, 1);

            throw new InvalidFragment("@{$event} has no fragment callback slot. A region view can use @press, @longPress, @doubleTap, @change and @submit.");
        }

        // The precompiler always compiles these to ['call' => ..., 'args' => [...]].
        // Node checks the name and args.
        if (! is_array($value) || ! isset($value['call']) || ! is_array($value['args'] ?? null)) {
            throw new InvalidFragment("{$slot}: expected a compiled callback.");
        }

        $node->{$method.($client ? 'Client' : '')}($value['call'], ...array_values($value['args']));
    }

    /** @param  array{navigate?: mixed, replace?: true, back?: true, transition?: string}  $slot  from the precompiler */
    private static function navigate(Node $node, mixed $slot): void
    {
        if (! is_array($slot)) {
            throw new InvalidFragment('_navigate: expected a compiled navigation.');
        }

        if (isset($slot['back'])) {
            $node->onPressBack($slot['transition'] ?? null);

            return;
        }

        $uri = $slot['navigate'] ?? null;
        if (! is_string($uri)) {
            throw new InvalidFragment('@navigate: the path must be a string, got '.get_debug_type($uri).'.');
        }

        $node->onPressNavigate($uri, $slot['replace'] ?? false, $slot['transition'] ?? null);
    }

    /** Apply the whitespace policy to attribute text (`:text="..."`). */
    private static function withText(array $attrs, ?string $mode, bool $run): array
    {
        if (isset($attrs['text']) && is_string($attrs['text'])) {
            $attrs['text'] = $run ? self::applyRunWhitespace($attrs['text'], $mode) : self::applyWhitespace($attrs['text'], $mode);
        }

        return $attrs;
    }

    /**
     * `whitespace-normal`, `-pre-line` or `-pre-wrap` in the class, the
     * only three the device's Tailwind parser knows. Variant-prefixed
     * spellings (`ios:whitespace-pre-wrap`) are left to the device.
     */
    private static function whitespaceMode(array $attrs): ?string
    {
        $class = $attrs['class'] ?? null;
        $class = is_array($class) ? Arr::toCssClasses($class) : $class;

        if (! is_string($class) || ! preg_match_all('/(?<=^|\s)whitespace-(normal|pre-line|pre-wrap)(?=\s|$)/', $class, $m)) {
            return null;
        }

        return end($m[1]);
    }

    private static function applyRunWhitespace(string $text, ?string $mode): string
    {
        return match ($mode ?? self::DEFAULT_WHITESPACE) {
            'pre-wrap' => $text,
            'pre-line' => self::collapsePreLine($text),
            default => preg_replace('/\s+/', ' ', $text),
        };
    }

    private static function applyWhitespace(string $text, ?string $mode): string
    {
        return match ($mode ?? self::DEFAULT_WHITESPACE) {
            'pre-wrap' => $text,
            'pre-line' => trim(self::collapsePreLine($text)),
            default => preg_replace('/\s+/', ' ', trim($text)),
        };
    }

    private static function collapsePreLine(string $text): string
    {
        return preg_replace('/[^\S\n]+/', ' ', preg_replace('/[^\S\n]*\n[^\S\n]*/', "\n", $text));
    }

    private static function captureRawRunIntoTopFrame(): void
    {
        $top = count(self::$textFrames) - 1;
        $text = self::normalizeRunText(ob_get_clean(), self::$textFrames[$top]['whitespace']);
        if ($text !== '') {
            self::$textFrames[$top]['children'][] = ['attrs' => ['text' => $text], 'children' => null];
        }
    }

    private static function normalizeRunText(string $raw, ?string $mode): string
    {
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8');

        if (($mode ?? self::DEFAULT_WHITESPACE) === 'normal' && trim($text) === '') {
            return '';
        }

        return self::applyRunWhitespace($text, $mode);
    }

    private static function normalizeLeafText(string $raw, ?string $mode): string
    {
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8');

        if (trim($text) === '') {
            return '';
        }

        return self::applyWhitespace($text, $mode);
    }
}
