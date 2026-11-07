<?php

namespace Native\Asgard\Support;

use LogicException;
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\ServerComponent;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Reflection over a server component, done once per class.
 *
 * regions:    region name => method name, from #[Region] on public,
 *             non-static methods declared on the class itself.
 * properties: property name => allowed builtin types, for public,
 *             non-static, non-readonly, typed, unlocked properties declared
 *             on the class itself.
 */
final class ComponentMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param  array<string, string>  $regions
     * @param  array<string, array{types: list<string>, nullable: bool}>  $properties
     */
    private function __construct(
        public readonly array $regions,
        public readonly array $properties,
    ) {}

    /** @param class-string<ServerComponent> $class */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= self::build($class);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private const HYDRATABLE = ['string', 'int', 'float', 'bool', 'array'];

    /** @param class-string<ServerComponent> $class */
    private static function build(string $class): self
    {
        $reflection = new ReflectionClass($class);

        $regions = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getAttributes(Region::class) as $attribute) {
                $name = $attribute->newInstance()->name;

                if (isset($regions[$name])) {
                    throw new LogicException("Region [{$name}] is declared twice on [{$class}].");
                }

                $regions[$name] = $method->getName();
            }
        }

        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()
                || $property->isReadOnly()
                || $property->getDeclaringClass()->getName() !== $class
                || $property->getAttributes(Locked::class) !== []) {
                continue;
            }

            $type = $property->getType();
            $named = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType => $type->getTypes(),
                default => null, // untyped or intersection: never hydrated
            };

            if ($named === null) {
                continue;
            }

            $types = [];
            $nullable = false;
            foreach ($named as $part) {
                if (! $part instanceof ReflectionNamedType || ! $part->isBuiltin()) {
                    continue 2; // any class type in the mix: no object hydration
                }
                $name = $part->getName();
                if ($name === 'null') {
                    $nullable = true;

                    continue;
                }
                if (! in_array($name, self::HYDRATABLE, true)) {
                    continue 2; // mixed, iterable, object, callable...
                }
                $types[] = $name;
                $nullable = $nullable || $part->allowsNull();
            }

            if ($types === []) {
                continue;
            }

            $properties[$property->getName()] = ['types' => $types, 'nullable' => $nullable];
        }

        return new self($regions, $properties);
    }
}
