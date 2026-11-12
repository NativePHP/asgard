<?php

namespace Native\Asgard\Support;

use Illuminate\Support\Facades\Log;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;

/**
 * Writes untrusted client state into a component's own public props.
 * Scalars, null and arrays of those only. No objects.
 *
 * No coercion either, except for a ServerScreen: its state is route
 * parameters and the query string, which are always strings on the wire,
 * so a numeric string fills an int or float property and "true", "false",
 * "1" or "0" fills a bool. Anything else still has to match exactly.
 */
final class Hydrator
{
    private const MAX_DEPTH = 32;

    /** @param array<string, mixed> $state */
    public static function hydrate(ServerComponent $component, array $state): void
    {
        $properties = ComponentMetadata::for($component::class)->properties;
        $ignored = [];

        foreach ($state as $key => $value) {
            $key = (string) $key;

            if (! isset($properties[$key])) {
                $ignored[] = $key;

                continue;
            }

            if (! self::isPlain($value, 0)) {
                throw new InvalidRequest("State [{$key}] must be a scalar, null or an array of those.");
            }

            if ($component instanceof ServerScreen && is_string($value)) {
                $value = self::fromRoute($value, $properties[$key]);
            }

            $component->{$key} = self::cast($key, $value, $properties[$key]);
        }

        if ($ignored !== [] && config('app.debug')) {
            Log::debug('Asgard ignored state keys the component does not accept.', [
                'component' => $component::class,
                'keys' => $ignored,
            ]);
        }
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

    /**
     * A route string converted to the first type the property takes that it
     * reads as. A string property takes it as it is.
     *
     * @param  array{types: list<string>, nullable: bool}  $spec
     */
    private static function fromRoute(string $value, array $spec): mixed
    {
        if (in_array('string', $spec['types'], true)) {
            return $value;
        }

        foreach ($spec['types'] as $type) {
            if ($type === 'int' && preg_match('/^-?(0|[1-9][0-9]{0,17})$/D', $value)) {
                return (int) $value;
            }
            if ($type === 'float' && is_numeric($value) && is_finite((float) $value) && trim($value) === $value) {
                return (float) $value;
            }
            if ($type === 'bool' && in_array($value, ['true', 'false', '1', '0'], true)) {
                return $value === 'true' || $value === '1';
            }
        }

        return $value;
    }

    /** @param array{types: list<string>, nullable: bool} $spec */
    private static function cast(string $key, mixed $value, array $spec): mixed
    {
        if ($value === null) {
            if ($spec['nullable']) {
                return null;
            }
        } else {
            foreach ($spec['types'] as $type) {
                $ok = match ($type) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    'float' => is_float($value) || is_int($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                };

                if ($ok) {
                    return $type === 'float' && is_int($value) && ! in_array('int', $spec['types'], true)
                        ? (float) $value
                        : $value;
                }
            }
        }

        $expected = implode('|', $spec['types']).($spec['nullable'] ? '|null' : '');

        throw new InvalidRequest("State [{$key}] must be of type {$expected}, ".get_debug_type($value).' given.');
    }
}
