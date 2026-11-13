<?php

namespace Native\Asgard\Support;

use LogicException;
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * Reflection over a server component, done once per class.
 *
 * regions:    region name => method name, from #[Region] on public,
 *             non-static methods declared on the class itself. A
 *             ServerScreen has exactly one, `screen` => `render`, and may
 *             not declare any other.
 * uncompressed: names of regions declared with compress: false.
 * shared:     names of regions declared with shared: true.
 * properties: property name => allowed builtin types, for public,
 *             non-static, non-readonly, typed, unlocked properties declared
 *             on the class itself.
 * actions:    method name => parameter spec, from #[Action] on public,
 *             non-static, non-magic methods declared on the class itself
 *             that are not also regions. Methods whose parameters could
 *             take anything other than plain JSON values (class types,
 *             callable, by-reference...) are left out, so they can never
 *             be called from the wire.
 */
final class ComponentMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param  array<string, string>  $regions
     * @param  list<string>  $uncompressed
     * @param  list<string>  $shared
     * @param  array<string, array{types: list<string>, nullable: bool}>  $properties
     * @param  array<string, array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}>  $actions
     */
    private function __construct(
        public readonly array $regions,
        public readonly array $properties,
        public readonly array $actions = [],
        public readonly array $uncompressed = [],
        public readonly array $shared = [],
    ) {}

    public function compresses(string $region): bool
    {
        return ! in_array($region, $this->uncompressed, true);
    }

    /** Whether the stream sends this region's fragment inline, rendered as a guest. */
    public function shares(string $region): bool
    {
        return in_array($region, $this->shared, true);
    }

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
        $uncompressed = [];
        $shared = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getAttributes(Region::class) as $attribute) {
                $region = $attribute->newInstance();
                $name = $region->name;

                if (isset($regions[$name])) {
                    throw new LogicException("Region [{$name}] is declared twice on [{$class}].");
                }

                $regions[$name] = $method->getName();

                if (! $region->compress) {
                    $uncompressed[] = $name;
                }

                if ($region->shared) {
                    $shared[] = $name;
                }
            }
        }

        if ($reflection->isSubclassOf(ServerScreen::class)) {
            if ($regions !== []) {
                throw new LogicException("[{$class}] is a screen, so it has one body, render(). Move #[Region] methods to a ServerComponent.");
            }

            $regions = [ServerScreen::REGION => 'render'];
            $screen = $reflection->getAttributes(Screen::class)[0] ?? null;

            if ($screen !== null && ! $screen->newInstance()->compress) {
                $uncompressed[] = ServerScreen::REGION;
            }

            if ($screen !== null && $screen->newInstance()->shared) {
                $shared[] = ServerScreen::REGION;
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

        $actions = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isAbstract()
                || $method->getDeclaringClass()->getName() !== $class
                || str_starts_with($method->getName(), '__')
                || $method->getAttributes(Action::class) === []
                || $method->getAttributes(Region::class) !== []
                || ($reflection->isSubclassOf(ServerScreen::class) && in_array($method->getName(), ['render', 'chrome'], true))) {
                continue;
            }

            $spec = self::actionSpec($method);
            if ($spec !== null) {
                $actions[$method->getName()] = $spec;
            }
        }

        return new self($regions, $properties, $actions, $uncompressed, $shared);
    }

    /** Types an action parameter may declare. Everything else refuses the action. */
    private const ACTION_TYPES = ['string', 'int', 'float', 'bool', 'array', 'false', 'true', 'null', 'mixed'];

    private const SCALAR_TYPES = ['string', 'int', 'float', 'bool', 'false', 'true'];

    /**
     * @return array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}|null
     */
    private static function actionSpec(ReflectionMethod $method): ?array
    {
        $params = [];
        $variadic = null;
        $scalarVariadic = false;

        foreach ($method->getParameters() as $parameter) {
            $spec = self::parameterSpec($parameter);
            if ($spec === null) {
                return null;
            }

            if ($parameter->isVariadic()) {
                $variadic = $spec;
                $scalarVariadic = $spec['types'] !== null
                    && array_diff($spec['types'], self::SCALAR_TYPES) === [];
            } else {
                $params[] = $spec;
            }
        }

        return [
            'params' => $params,
            'required' => $method->getNumberOfRequiredParameters(),
            'variadic' => $variadic,
            'scalarVariadic' => $scalarVariadic,
        ];
    }

    /**
     * types null means any JSON value (untyped or mixed).
     *
     * @return array{types: list<string>|null, nullable: bool}|null
     */
    private static function parameterSpec(ReflectionParameter $parameter): ?array
    {
        if ($parameter->isPassedByReference()) {
            return null;
        }

        $type = $parameter->getType();
        if ($type === null) {
            return ['types' => null, 'nullable' => true];
        }

        $named = self::namedTypes($type);
        if ($named === null) {
            return null;
        }

        $types = [];
        $nullable = $type->allowsNull();
        foreach ($named as $part) {
            if (! $part->isBuiltin() || ! in_array($part->getName(), self::ACTION_TYPES, true)) {
                return null; // class, interface, self, callable, iterable, object...
            }
            if ($part->getName() === 'mixed') {
                return ['types' => null, 'nullable' => true];
            }
            if ($part->getName() !== 'null') {
                $types[] = $part->getName();
            }
        }

        return ['types' => $types, 'nullable' => $nullable];
    }

    /** @return list<ReflectionNamedType>|null null for intersection or DNF types */
    private static function namedTypes(ReflectionType $type): ?array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $part) {
                if (! $part instanceof ReflectionNamedType) {
                    return null;
                }
                $parts[] = $part;
            }

            return $parts;
        }

        return null;
    }
}
