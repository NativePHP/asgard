<?php

namespace Native\Asgard\Support;

use Illuminate\Support\Facades\Log;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\ServerComponent;

/**
 * Writes untrusted client state into a component's own public props.
 * Scalars, null and arrays of those only. No coercion, no objects.
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
