<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Http\Concerns\RendersRegions;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;
use ReflectionObject;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * Runs the server half of a `#[Server]` method and answers with the props
 * it changed.
 *
 *     → {"protocol":1,"component":"app.native.screens.home","method":"roll","args":[],"state":{…},"platform":"ios"}
 *     ← {"protocol":1,"delta":{"roll":4}}
 *
 * The component is hydrated from the state the device sent, exactly as a
 * render hydrates it. The method runs. Then every public prop is compared
 * with its value straight after hydration, by its serialize() form, and
 * only the ones that differ go back. The device applies each one only if
 * its own value has not moved since it sent the call.
 *
 * A call changes state, so its signature must carry a nonce, like an
 * action. Nothing is rendered unless the method calls refresh(). Regions of
 * this component it names are rendered after the delta is worked out and
 * travel in `fragments`, keyed by region. Other components it names travel
 * as hints in `refresh`:
 *
 *     ← {"protocol":1,"delta":{"roll":4},
 *        "fragments":{"stats":{"version":"…","fragment":{…}}},
 *        "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
 */
class CallController
{
    use ReadsAsgardRequests;
    use RendersRegions;

    private const FIELDS = ['protocol', 'component', 'method', 'args', 'state', 'platform'];

    private const MAX_DEPTH = 32;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
        protected RegionViews $views,
        protected PushVersions $versions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // A call is not a safe request (§7.3). The middleware has already
        // refused a nonce it has seen before.
        if ($this->secure($request)->nonce === null) {
            return response()->json(['error' => 'nonce_required'], 403);
        }

        // Plain array lookups only. The wire strings never name a class or method.
        $class = $this->registry->resolve($payload['component']);

        if ($class === null) {
            return response()->json(['error' => 'unknown_component'], 404);
        }

        $metadata = ComponentMetadata::for($class);
        $spec = $metadata->servers[$payload['method']] ?? null;

        if ($spec === null || ! ActionInvoker::accepts($spec, count($payload['args']))) {
            return response()->json(['error' => 'unknown_method'], 404);
        }

        $component = $this->container->make($class);
        $component->setAsgardContext('', $payload['platform']);

        try {
            Hydrator::hydrate($component, $payload['state']);

            // From the state the device sent, as a render works them out.
            $channels = $this->channelsOf($component);
            $before = $this->fingerprints($component);

            ActionInvoker::invoke($component, $payload['method'], $spec, $payload['args'], 'args');
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        try {
            $delta = $this->delta($component, $before);
        } catch (UnexpectedValueException $e) {
            report($e);

            return config('app.debug')
                ? $this->error(500, 'invalid_delta', $class.'::'.$payload['method'].'() left a prop that cannot be sent. '.$e->getMessage())
                : response()->json(['error' => 'invalid_delta'], 500);
        }

        $answer = ['protocol' => self::PROTOCOL, 'delta' => (object) $delta];

        $more = $this->refreshed($component, $metadata, $payload['component'], $channels, $payload['platform']);

        if ($more instanceof JsonResponse) {
            return $more;
        }

        if ($more['fragments'] !== []) {
            $answer['fragments'] = $more['fragments'];
        }

        if ($more['refresh'] !== []) {
            $answer['refresh'] = $more['refresh'];
        }

        // Never compressed, fragments or not: the delta can mix the
        // server's values with text the device chose (§7.4).

        return response()->json($answer, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{component: string, method: string, args: list<mixed>, state: array<string, mixed>, platform: string}
     */
    protected function payload(Request $request): array
    {
        $body = $this->body($request);

        $extra = array_diff(array_map('strval', array_keys($body)), self::FIELDS);
        if ($extra !== []) {
            throw new InvalidRequest('Body has unknown fields.');
        }

        if (! array_key_exists('method', $body) || ! array_key_exists('args', $body)) {
            throw new InvalidRequest('A call needs both method and args.');
        }

        if ($problem = Callback::nameProblem($body['method']) ?? Callback::argsProblem($body['args'])) {
            throw new InvalidRequest(str_replace("'call'", "'method'", $problem));
        }

        return [
            'component' => $this->component($body['component'] ?? null),
            'method' => $body['method'],
            'args' => $body['args'],
            'state' => $this->state($body['state'] ?? null),
            'platform' => $this->platform($body['platform'] ?? null),
        ];
    }

    /**
     * Every public prop's serialize() form. An unset typed prop is absent,
     * so one the method fills in counts as changed.
     *
     * @return array<string, string>
     */
    protected function fingerprints(ServerComponent $component): array
    {
        $prints = [];

        foreach ($this->properties($component) as $name => $property) {
            if ($property->isInitialized($component)) {
                $prints[$name] = serialize($property->getValue($component));
            }
        }

        return $prints;
    }

    /**
     * The props whose value differs from what hydration left, checked to
     * be plain JSON values.
     *
     * @param  array<string, string>  $before
     * @return array<string, mixed>
     */
    protected function delta(ServerComponent $component, array $before): array
    {
        $delta = [];

        foreach ($this->properties($component) as $name => $property) {
            if (! $property->isInitialized($component)) {
                continue;
            }

            $value = $property->getValue($component);

            if (array_key_exists($name, $before) && $before[$name] === serialize($value)) {
                continue;
            }

            if (! self::isJsonValue($value, 0)) {
                throw new UnexpectedValueException("[{$name}] must be null, a bool, a finite number, a UTF-8 string, or an array of those.");
            }

            $delta[$name] = $value;
        }

        return $delta;
    }

    /** @return array<string, ReflectionProperty> */
    protected function properties(ServerComponent $component): array
    {
        $properties = [];

        foreach ((new ReflectionObject($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $properties[$property->getName()] = $property;
            }
        }

        return $properties;
    }

    private static function isJsonValue(mixed $value, int $depth): bool
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

            if (! self::isJsonValue($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
