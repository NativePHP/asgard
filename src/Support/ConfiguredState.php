<?php

namespace Native\Asgard\Support;

use Closure;
use InvalidArgumentException;

/**
 * The app-wide keys in `asgard.state`, added to the delta of every answer
 * that carries one.
 *
 * A value may be a closure or a `[Class::class, 'method']` callable, called
 * with no arguments each time an answer is built. The callable array is the
 * one to use in config/asgard.php, because a closure there stops
 * `php artisan config:cache` working. Anything else is sent as it is.
 *
 * A key that is not a property name, or a value that is not plain JSON, is
 * a mistake in the app's config and throws.
 */
final class ConfiguredState
{
    /** What a key must look like: a PHP property name, as every other state key is. */
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D';

    /** @return array<string, mixed> */
    public static function resolve(): array
    {
        $config = config('asgard.state', []);

        if ($config === null) {
            return [];
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException('asgard.state must be an array of key => value.');
        }

        $state = [];

        foreach ($config as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException(
                    'asgard.state key ['.$key.'] is not a property name. Keys are letters, digits and underscores, '
                    .'starting with a letter or underscore, at most 100 characters.'
                );
            }

            if (self::resolvable($value)) {
                $value = $value();
            }

            if (! JsonValue::is($value)) {
                throw new InvalidArgumentException(
                    'asgard.state ['.$key.'] is a '.get_debug_type($value).'. A value must be null, a bool, a finite number, '
                    .'a UTF-8 string or an array of those.'
                );
            }

            $state[$key] = $value;
        }

        return $state;
    }

    /** A closure, or a list of a class name and one of its static methods. */
    public static function resolvable(mixed $value): bool
    {
        if ($value instanceof Closure) {
            return true;
        }

        return is_array($value) && array_is_list($value) && count($value) === 2
            && is_string($value[0]) && is_string($value[1])
            && class_exists($value[0]) && is_callable($value);
    }
}
