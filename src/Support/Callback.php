<?php

namespace Native\Asgard\Support;

/**
 * The shape of a callback slot and of an action request:
 * {"call": "method", "args": [...], "target"?: "server"|"client"}.
 *
 * Shared by the Node builder, the fragment validator and the render
 * controller so all three agree on what is allowed.
 */
final class Callback
{
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D';

    public const MAX_ARGS = 16;

    public const MAX_DEPTH = 4;

    public const TARGETS = ['server', 'client'];

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
