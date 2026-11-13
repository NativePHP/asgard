<?php

namespace Native\Asgard;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Native\Asgard\Attributes\Screen;
use ReflectionClass;

/**
 * Server screens and the routes they answer (protocol §12, "Screens").
 *
 * A screen is registered by class, from `config('asgard.screens')` or
 * Asgard::screen(). Its route comes from its #[Screen] attribute, so the
 * route and the class cannot drift apart. Registering a screen also
 * registers it as a component at its address, so render, actions and the
 * push stream find it the way they find any component.
 *
 * Like the component map, this is written by the developer and read as
 * plain array lookups. Nothing from the wire ever names a class.
 */
class ScreenRegistry
{
    /**
     * Literal segments and `{param}` segments, with `{param?}` allowed last.
     * The root is just `/`.
     */
    public const URI_PATTERN = '#^/$|^(/([A-Za-z0-9._~-]+|\{[A-Za-z_][A-Za-z0-9_]*\}))*(/([A-Za-z0-9._~-]+|\{[A-Za-z_][A-Za-z0-9_]*\??\}))$#D';

    /** @var array<string, array{uri: string, class: class-string<ServerScreen>}> address => screen */
    protected array $screens = [];

    /** @param list<class-string<ServerScreen>> $screens */
    public function __construct(
        protected ComponentRegistry $components,
        array $screens = [],
    ) {
        foreach ($screens as $class) {
            $this->screen($class);
        }
    }

    /** @param class-string<ServerScreen> $class */
    public function screen(string $class): static
    {
        if (! is_subclass_of($class, ServerScreen::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".ServerScreen::class.'.');
        }

        $attribute = (new ReflectionClass($class))->getAttributes(Screen::class)[0] ?? null;

        if ($attribute === null) {
            throw new InvalidArgumentException("[{$class}] needs a #[Screen('/uri')] attribute.");
        }

        $screen = $attribute->newInstance();
        $uri = $screen->uri === '/' ? '/' : '/'.trim($screen->uri, '/');

        if (strlen($uri) > 255 || ! preg_match(self::URI_PATTERN, $uri)) {
            throw new InvalidArgumentException("Screen route [{$screen->uri}] on [{$class}] must be a path of literal and {param} segments, like /promos/{id}.");
        }

        $address = $screen->name ?? self::addressFor($class);

        foreach ($this->screens as $other => $entry) {
            if ($entry['uri'] === $uri && $other !== $address) {
                throw new InvalidArgumentException("Screen route [{$uri}] is already taken by [{$entry['class']}].");
            }
        }

        $existing = $this->components->resolve($address);
        if ($existing !== null && $existing !== $class) {
            throw new InvalidArgumentException("Asgard address [{$address}] is already used by [{$existing}].");
        }

        $this->components->component($address, $class);
        $this->screens[$address] = ['uri' => $uri, 'class' => $class];

        return $this;
    }

    /** The address of a registered screen class, or null. */
    public function addressOf(string $class): ?string
    {
        foreach ($this->screens as $address => $entry) {
            if ($entry['class'] === ltrim($class, '\\')) {
                return $address;
            }
        }

        return null;
    }

    /**
     * The route list the device fetches, in registration order.
     *
     * @return list<array{uri: string, screen: string}>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->screens as $address => $entry) {
            $routes[] = ['uri' => $entry['uri'], 'screen' => $address];
        }

        return $routes;
    }

    /** A short hash of the route list, so a client can tell when it changed. */
    public function version(): string
    {
        return substr(hash('sha256', json_encode($this->routes(), JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /** @return array<string, array{uri: string, class: class-string<ServerScreen>}> */
    public function all(): array
    {
        return $this->screens;
    }

    /** App\Asgard\PromoScreen => app.asgard.promo_screen, as protocol §4 addresses components. */
    public static function addressFor(string $class): string
    {
        return implode('.', array_map(
            static fn (string $segment): string => Str::snake($segment),
            explode('\\', ltrim($class, '\\')),
        ));
    }
}
