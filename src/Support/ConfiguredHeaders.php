<?php

namespace Native\Asgard\Support;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app-wide headers in `asgard.headers`, added to every answer from an
 * Asgard endpoint. They are plain HTTP headers: the response signature does
 * not cover them and they are not encrypted.
 *
 * A value may be a string, a number, or a closure or `[Class::class,
 * 'method']` callable that gives one, called each time a response goes
 * out. null leaves the header out. A name the server sets, signs or that a
 * client reads itself is refused, and so is a value with a line break.
 */
final class ConfiguredHeaders
{
    /** Names the server or the transport sets, or that a client reads. */
    public const RESERVED = [
        'allow', 'cache-control', 'connection', 'keep-alive', 'set-cookie',
        'signature', 'signature-input', 'trailer', 'transfer-encoding', 'upgrade',
        'x-accel-buffering',
    ];

    /** Every name starting with one of these is reserved too. */
    public const RESERVED_PREFIXES = ['content-', 'asgard-', 'x-asgard-'];

    /** @return array<string, string> */
    public static function resolve(): array
    {
        $config = config('asgard.headers', []);

        if ($config === null) {
            return [];
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException('asgard.headers must be an array of header name => value.');
        }

        $headers = [];

        foreach ($config as $name => $value) {
            $name = (string) $name;

            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                throw new InvalidArgumentException("asgard.headers [{$name}] is not a header name.");
            }

            if (self::reserved(strtolower($name))) {
                throw new InvalidArgumentException("asgard.headers cannot set [{$name}]: Asgard sets it, signs it or a client reads it.");
            }

            if (ConfiguredState::resolvable($value)) {
                $value = $value();
            }

            if ($value === null) {
                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException("asgard.headers [{$name}] must be a string, got a ".get_debug_type($value).'.');
            }

            $value = (string) $value;

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException("asgard.headers [{$name}] has a line break in it.");
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /** Add the configured headers to a response. */
    public static function apply(Response $response): Response
    {
        foreach (self::resolve() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    public static function reserved(string $lower): bool
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return in_array($lower, self::RESERVED, true);
    }
}
