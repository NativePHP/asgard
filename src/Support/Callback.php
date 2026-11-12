<?php

namespace Native\Asgard\Support;

use Native\Asgard\Exceptions\InvalidFragment;

/**
 * The shapes of a callback slot.
 *
 * A call runs a method, on the server or the device, and is also the shape
 * of an action request:
 *     {"call": "method", "args": [...], "target"?: "server"|"client"}
 *
 * A navigation moves the app to a route the device knows, or back:
 *     {"navigate": "/promos/42", "replace"?: true, "transition"?: "fade"}
 *     {"back": true, "transition"?: "fade"}
 *
 * Shared by the Node and chrome builders, the fragment and chrome
 * validators and the render controller so they all agree on what is
 * allowed.
 */
final class Callback
{
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D';

    public const MAX_ARGS = 16;

    public const MAX_DEPTH = 4;

    public const TARGETS = ['server', 'client'];

    public const MAX_URI_LENGTH = 2048;

    /** The device's Transition values (nativephp/mobile). */
    public const TRANSITIONS = [
        'slide_from_right', 'slide_from_left', 'slide_from_bottom', 'fade',
        'fade_from_bottom', 'scale_from_center', 'parallax_push', 'morph', 'none',
    ];

    /** Slots a navigation may sit in. A value change or a submit never moves the app. */
    public const NAVIGABLE_SLOTS = ['on_press', 'on_long_press', 'on_double_tap'];

    /**
     * A call slot, checked.
     *
     * @param  array<int|string, mixed>  $args
     * @return array{call: string, args: list<mixed>, target?: 'client'}
     */
    public static function call(string $method, array $args, string $target = 'server'): array
    {
        if ($problem = self::nameProblem($method) ?? self::argsProblem($args)) {
            throw new InvalidFragment($problem);
        }

        $slot = ['call' => $method, 'args' => $args];
        if ($target === 'client') {
            $slot['target'] = 'client';
        }

        return $slot;
    }

    /**
     * A navigation slot, checked.
     *
     * @return array{navigate: string, replace?: true, transition?: string}
     */
    public static function navigate(string $uri, bool $replace = false, ?string $transition = null): array
    {
        $slot = ['navigate' => $uri];
        if ($replace) {
            $slot['replace'] = true;
        }
        if ($transition !== null) {
            $slot['transition'] = $transition;
        }

        if ($problem = self::navigationProblem($slot)) {
            throw new InvalidFragment($problem);
        }

        return $slot;
    }

    /**
     * A back slot, checked.
     *
     * @return array{back: true, transition?: string}
     */
    public static function back(?string $transition = null): array
    {
        $slot = ['back' => true];
        if ($transition !== null) {
            $slot['transition'] = $transition;
        }

        if ($problem = self::navigationProblem($slot)) {
            throw new InvalidFragment($problem);
        }

        return $slot;
    }

    /**
     * Check one slot from a fragment or chrome and return its clean form.
     * Strings and unknown shapes are refused. A navigation is only allowed
     * in the press slots.
     *
     * @return array<string, mixed>
     */
    public static function validateSlot(mixed $value, string $path, string $slot = 'on_press'): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new InvalidFragment("{$path}: callback must be an object like {\"call\": \"method\", \"args\": []} or {\"navigate\": \"/path\"}.");
        }

        if (self::isNavigation($value)) {
            if (! in_array($slot, self::NAVIGABLE_SLOTS, true)) {
                throw new InvalidFragment("{$path}: {$slot} cannot navigate. Only ".implode(', ', self::NAVIGABLE_SLOTS).' can.');
            }

            if ($problem = self::navigationProblem($value)) {
                throw new InvalidFragment("{$path}: {$problem}");
            }

            return array_intersect_key($value, array_flip(['navigate', 'replace', 'back', 'transition']));
        }

        $extra = array_diff(array_map('strval', array_keys($value)), ['call', 'args', 'target']);
        if ($extra !== []) {
            throw new InvalidFragment("{$path}: unknown callback field [".implode(', ', $extra).'].');
        }

        if (! array_key_exists('call', $value) || ! array_key_exists('args', $value)) {
            throw new InvalidFragment("{$path}: callback needs both 'call' and 'args'.");
        }

        if ($problem = self::nameProblem($value['call']) ?? self::argsProblem($value['args'])) {
            throw new InvalidFragment("{$path}: {$problem}");
        }

        $clean = ['call' => $value['call'], 'args' => $value['args']];

        if (array_key_exists('target', $value)) {
            if (! in_array($value['target'], self::TARGETS, true)) {
                throw new InvalidFragment("{$path}: 'target' must be \"server\" or \"client\".");
            }
            if ($value['target'] === 'client') {
                $clean['target'] = 'client';
            }
        }

        return $clean;
    }

    /** True when a slot object is a navigation rather than a call. */
    public static function isNavigation(array $slot): bool
    {
        return array_key_exists('navigate', $slot) || array_key_exists('back', $slot);
    }

    /**
     * What is wrong with a navigation slot, or null when it is fine.
     *
     * The URI is an app path: it starts with one slash, carries no scheme or
     * host, and has no whitespace or control characters. Whether a route
     * matches it is the device's question, since only the device knows its
     * own routes.
     */
    public static function navigationProblem(array $slot): ?string
    {
        if (array_key_exists('back', $slot)) {
            $extra = array_diff(array_map('strval', array_keys($slot)), ['back', 'transition']);
            if ($extra !== []) {
                return 'unknown back field ['.implode(', ', $extra).'].';
            }
            if ($slot['back'] !== true) {
                return "'back' must be true.";
            }
        } else {
            $extra = array_diff(array_map('strval', array_keys($slot)), ['navigate', 'replace', 'transition']);
            if ($extra !== []) {
                return 'unknown navigate field ['.implode(', ', $extra).'].';
            }
            if ($problem = self::uriProblem($slot['navigate'] ?? null)) {
                return $problem;
            }
            if (array_key_exists('replace', $slot) && ! is_bool($slot['replace'])) {
                return "'replace' must be a boolean.";
            }
        }

        if (array_key_exists('transition', $slot) && ! in_array($slot['transition'], self::TRANSITIONS, true)) {
            return "'transition' must be one of ".implode(', ', self::TRANSITIONS).'.';
        }

        return null;
    }

    /** What is wrong with an app path, or null when it is fine. */
    public static function uriProblem(mixed $uri): ?string
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > self::MAX_URI_LENGTH) {
            return "'navigate' must be an app path of at most ".self::MAX_URI_LENGTH.' bytes.';
        }

        if ($uri[0] !== '/' || str_starts_with($uri, '//') || str_contains($uri, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            return "'navigate' must be an app path starting with a single '/', like /promos/42. Web URLs are not allowed.";
        }

        return null;
    }

    /** Returns what is wrong with a method name, or null when it is fine. */
    public static function nameProblem(mixed $call): ?string
    {
        if (! is_string($call) || ! preg_match(self::NAME_PATTERN, $call)) {
            return "'call' must be a method name matching ".self::NAME_PATTERN.'.';
        }

        return null;
    }

    /** Returns what is wrong with an argument list, or null when it is fine. */
    public static function argsProblem(mixed $args): ?string
    {
        if (! is_array($args) || ! array_is_list($args)) {
            return "'args' must be a list.";
        }

        if (count($args) > self::MAX_ARGS) {
            return "'args' may hold at most ".self::MAX_ARGS.' values.';
        }

        foreach ($args as $i => $arg) {
            if (! self::isValue($arg, 0)) {
                return "'args[{$i}]' must be null, a bool, a number, a string, or a list or object of those nested at most ".self::MAX_DEPTH.' deep.';
            }
        }

        return null;
    }

    private static function isValue(mixed $value, int $depth): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (! is_array($value) || $depth >= self::MAX_DEPTH) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isValue($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
