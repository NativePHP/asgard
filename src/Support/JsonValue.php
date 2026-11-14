<?php

namespace Native\Asgard\Support;

/**
 * What the server may put in a delta: null, a bool, a finite number, a
 * UTF-8 string, or an array of those, at most 32 levels deep.
 */
final class JsonValue
{
    public const MAX_DEPTH = 32;

    public static function is(mixed $value, int $depth = 0): bool
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8');
        }

        if (! is_array($value) || $depth >= self::MAX_DEPTH) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                return false;
            }

            if (! self::is($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
