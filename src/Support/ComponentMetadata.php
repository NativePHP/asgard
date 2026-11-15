<?php

namespace Native\Asgard\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use LogicException;
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Computed;
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Attributes\Server;
use Native\Asgard\Attributes\Upload;
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
 * maxAges:    region name => seconds, for regions that set maxAge.
 * properties: property name => allowed builtin types, for public,
 *             non-static, non-readonly, typed, unlocked properties declared
 *             on the class itself.
 * actions:    method name => parameter spec, from #[Action] on public,
 *             non-static, non-magic methods declared on the class itself
 *             that are not also regions. Methods whose parameters could
 *             take anything other than plain JSON values (class types,
 *             callable, by-reference...) are left out, so they can never
 *             be called from the wire.
 * servers:    method name => parameter spec, from #[Server] under the same
 *             rules as actions. A call request can only run these.
 * uploads:    method name => upload spec, from #[Upload] under the same
 *             rules, except that the first parameter must take an
 *             UploadedFile. The spec covers the parameters after it, with
 *             the attribute's size cap in bytes and its mime types. An
 *             upload request can only run these.
 * computed:   names of the #[Computed] methods: public, non-static,
 *             non-abstract, non-magic, with no required parameters. A
 *             parent's count too, since the wire never picks which runs. Their values go in every delta. Such
 *             a method is never an action, server method, upload or
 *             region, and a public prop of the same name is refused, as is
 *             a #[Computed] that breaks any of these rules.
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
     * @param  array<string, array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}>  $servers
     * @param  array<string, array{spec: array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}, maxSize: int|null, mimes: list<string>}>  $uploads
     * @param  list<string>  $computed
     * @param  array<string, int>  $maxAges
     */
    private function __construct(
        public readonly array $regions,
        public readonly array $properties,
        public readonly array $actions = [],
        public readonly array $uncompressed = [],
        public readonly array $shared = [],
        public readonly array $servers = [],
        public readonly array $uploads = [],
        public readonly array $computed = [],
        public readonly array $maxAges = [],
    ) {}

    /**
     * The longest a client may treat a copy of this region as fresh, in
     * seconds: the region's own maxAge, else `asgard.cache.max_age`. Null
     * means the server sets no limit.
     */
    public function maxAge(string $region): ?int
    {
        if (array_key_exists($region, $this->maxAges)) {
            return $this->maxAges[$region];
        }

        $config = config('asgard.cache.max_age');

        if ($config === null || $config === '') {
            return null;
        }

        if (! is_numeric($config) || (int) $config != $config || (int) $config < 0) {
            throw new InvalidArgumentException('asgard.cache.max_age must be a whole number of seconds, 0 or more, or null.');
        }

        return (int) $config;
    }

    /**
     * `made_at`, and `max_age` when the server sets one, for a fragment of
     * this region made at $madeAt (Unix milliseconds).
     *
     * @return array{made_at: int, max_age?: int}
     */
    public function stamp(string $region, int $madeAt): array
    {
        $maxAge = $this->maxAge($region);

        return ['made_at' => $madeAt, ...($maxAge === null ? [] : ['max_age' => $maxAge])];
    }

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
        $maxAges = [];
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

                if ($region->maxAge !== null) {
                    $maxAges[$name] = self::maxAgeOf($region->maxAge, "#[Region('{$name}')] on [{$class}]");
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

            if ($screen !== null && $screen->newInstance()->maxAge !== null) {
                $maxAges[ServerScreen::REGION] = self::maxAgeOf($screen->newInstance()->maxAge, "#[Screen] on [{$class}]");
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

        $computed = self::computed($reflection);
        $actions = self::callable($reflection, Action::class);
        $servers = self::callable($reflection, Server::class);
        $uploads = self::uploadable($reflection);

        return new self($regions, $properties, $actions, $uncompressed, $shared, $servers, $uploads, $computed, $maxAges);
    }

    private static function maxAgeOf(int $maxAge, string $where): int
    {
        if ($maxAge < 0) {
            throw new LogicException("{$where}: maxAge must be 0 or more seconds.");
        }

        return $maxAge;
    }

    /** The wire-facing attributes a #[Computed] method may not also carry. */
    private const NOT_WITH_COMPUTED = [Action::class, Server::class, Upload::class, Region::class];

    /**
     * The names of the #[Computed] methods. A mistake in one throws rather
     * than leaving it out quietly, since a value the app expects would
     * otherwise never arrive.
     *
     * @return list<string>
     */
    private static function computed(ReflectionClass $reflection): array
    {
        $class = $reflection->getName();
        $computed = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getAttributes(Computed::class) === []) {
                continue;
            }

            $name = $method->getName();
            $where = "#[Computed] on [{$class}::{$name}()]";

            if (! $method->isPublic() || $method->isStatic() || $method->isAbstract() || str_starts_with($name, '__')) {
                throw new LogicException("{$where} must be a public, non-static, non-magic method.");
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                throw new LogicException("{$where} cannot have required parameters.");
            }

            foreach (self::NOT_WITH_COMPUTED as $attribute) {
                if ($method->getAttributes($attribute) !== []) {
                    throw new LogicException("{$where} cannot also be #[".class_basename($attribute).'].');
                }
            }

            if ($reflection->isSubclassOf(ServerScreen::class) && in_array($name, ['render', 'chrome'], true)) {
                throw new LogicException("{$where}: a screen's {$name}() cannot be computed.");
            }

            if ($reflection->hasProperty($name) && $reflection->getProperty($name)->isPublic() && ! $reflection->getProperty($name)->isStatic()) {
                throw new LogicException("{$where} has the same name as a public prop, so one would hide the other in the delta.");
            }

            $computed[] = $name;
        }

        return $computed;
    }

    /**
     * method name => parameter spec, for the methods carrying this
     * attribute that the wire may run: public, non-static, non-abstract,
     * non-magic, declared on the class itself, not a region and not a
     * screen's render() or chrome().
     *
     * @param  class-string  $attribute
     * @return array<string, array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}>
     */
    private static function callable(ReflectionClass $reflection, string $attribute): array
    {
        $methods = [];

        foreach (self::marked($reflection, $attribute) as $method) {
            $spec = self::actionSpec($method);
            if ($spec !== null) {
                $methods[$method->getName()] = $spec;
            }
        }

        return $methods;
    }

    /**
     * The public methods carrying this attribute that the wire may run at
     * all, under the rules callable() describes.
     *
     * @param  class-string  $attribute
     * @return list<ReflectionMethod>
     */
    private static function marked(ReflectionClass $reflection, string $attribute): array
    {
        $class = $reflection->getName();
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()
                || $method->isAbstract()
                || $method->getDeclaringClass()->getName() !== $class
                || str_starts_with($method->getName(), '__')
                || $method->getAttributes($attribute) === []
                || $method->getAttributes(Region::class) !== []
                || ($reflection->isSubclassOf(ServerScreen::class) && in_array($method->getName(), ['render', 'chrome'], true))) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * method name => upload spec, for the #[Upload] methods the wire may
     * run. The first parameter must take an UploadedFile: a plain class
     * type that UploadedFile is, not nullable, not variadic and not by
     * reference. The rest must pass as an action's parameters do.
     *
     * @return array<string, array{spec: array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}, maxSize: int|null, mimes: list<string>}>
     */
    private static function uploadable(ReflectionClass $reflection): array
    {
        $uploads = [];

        foreach (self::marked($reflection, Upload::class) as $method) {
            $file = $method->getParameters()[0] ?? null;
            $type = $file?->getType();

            if ($file === null
                || $file->isVariadic()
                || $file->isPassedByReference()
                || ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
                || $type->allowsNull()
                || ! is_a(UploadedFile::class, $type->getName(), true)) {
                continue;
            }

            $spec = self::actionSpec($method, 1);
            if ($spec === null) {
                continue;
            }

            $upload = $method->getAttributes(Upload::class)[0]->newInstance();

            try {
                $maxSize = $upload->maxSize === null ? null : Upload::bytes($upload->maxSize);
            } catch (InvalidArgumentException $e) {
                throw new LogicException("#[Upload] on [{$reflection->getName()}::{$method->getName()}()]: ".$e->getMessage());
            }

            $uploads[$method->getName()] = [
                'spec' => $spec,
                'maxSize' => $maxSize,
                'mimes' => array_values(array_map(fn ($mime) => strtolower((string) $mime), $upload->mimes ?? [])),
            ];
        }

        return $uploads;
    }

    /** Types an action parameter may declare. Everything else refuses the action. */
    private const ACTION_TYPES = ['string', 'int', 'float', 'bool', 'array', 'false', 'true', 'null', 'mixed'];

    private const SCALAR_TYPES = ['string', 'int', 'float', 'bool', 'false', 'true'];

    /**
     * @return array{params: list<array{types: list<string>|null, nullable: bool}>, required: int, variadic: array{types: list<string>|null, nullable: bool}|null, scalarVariadic: bool}|null
     */
    private static function actionSpec(ReflectionMethod $method, int $skip = 0): ?array
    {
        $params = [];
        $variadic = null;
        $scalarVariadic = false;

        foreach (array_slice($method->getParameters(), $skip) as $parameter) {
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
            'required' => max(0, $method->getNumberOfRequiredParameters() - $skip),
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
