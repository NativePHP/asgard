<?php

declare(strict_types=1);

namespace Native\Asgard\Support;

use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\ServerComponent;

/**
 * Runs an #[Action] or #[Server] method with positional, already
 * shape-checked args.
 * No container, no model or enum binding: each arg must already be the
 * plain JSON value the parameter declares. A mismatch is InvalidRequest.
 *
 * @phpstan-type ParamSpec array{types: list<string>|null, nullable: bool}
 * @phpstan-type ActionSpec array{params: list<ParamSpec>, required: int, variadic: ParamSpec|null, scalarVariadic: bool}
 */
final class ActionInvoker
{
    /**
     * Whether this many args can be passed to the action at all.
     *
     * @param  ActionSpec  $spec
     */
    public static function accepts(array $spec, int $count): bool
    {
        if ($count < $spec['required']) {
            return false;
        }

        return $count <= count($spec['params']) || $spec['scalarVariadic'];
    }

    /**
     * @param  ActionSpec  $spec
     * @param  list<mixed>  $args
     * @param  string  $field  What the error calls the args.
     * @param  list<mixed>  $leading  Passed ahead of the args, unchecked: the
     *                                server's own values, such as an upload's
     *                                file. The spec describes only the args.
     */
    public static function invoke(ServerComponent $component, string $method, array $spec, array $args, string $field = 'action.args', array $leading = []): void
    {
        foreach ($args as $i => $arg) {
            $param = $spec['params'][$i] ?? $spec['variadic'];

            if ($param === null || ! self::fits($arg, $param)) {
                throw new InvalidRequest("{$field}[{$i}] does not fit the parameter type.");
            }
        }

        // Strict types are on in this file, so PHP does no coercion either.
        $component->{$method}(...$leading, ...$args);
    }

    /** @param ParamSpec $param */
    private static function fits(mixed $value, array $param): bool
    {
        if ($param['types'] === null) {
            return true;
        }

        if ($value === null) {
            return $param['nullable'];
        }

        foreach ($param['types'] as $type) {
            $ok = match ($type) {
                'string' => is_string($value),
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                'false' => $value === false,
                'true' => $value === true,
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
