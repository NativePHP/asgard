<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;
use Throwable;

class RenderController
{
    use ReadsAsgardRequests;

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

        // An action is not a safe request, so its signature must carry a
        // nonce (§7.3). The middleware has already refused a repeated one.
        if ($payload['action'] !== null && $this->secure($request)->nonce === null) {
            return response()->json(['error' => 'nonce_required'], 403);
        }

        // Plain array lookups only. The wire strings never name a class or method.
        $class = $this->registry->resolve($payload['component']);
        $metadata = $class ? ComponentMetadata::for($class) : null;
        $method = $metadata?->regions[$payload['region']] ?? null;

        if ($class === null || $method === null) {
            return response()->json(['error' => 'unknown_component'], 404);
        }

        // Same rule for actions: the wire `call` is only a key into the map of
        // #[Action] methods built by reflection. Anything else is unknown.
        $action = $payload['action'];
        $actionSpec = $action ? ($metadata->actions[$action['call']] ?? null) : null;

        if ($action && ($actionSpec === null || ! ActionInvoker::accepts($actionSpec, count($action['args'])))) {
            return response()->json(['error' => 'unknown_action'], 404);
        }

        $component = $this->container->make($class);
        $component->setAsgardContext($payload['region'], $payload['platform']);

        try {
            Hydrator::hydrate($component, $payload['state']);

            // Read before the action and the render, so a push that lands
            // meanwhile makes the token stale and the stream sends it again.
            $version = $this->version($component, $payload);

            if ($action) {
                ActionInvoker::invoke($component, $action['call'], $actionSpec, $action['args']);
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $result = $this->container->call([$component, $method]);

        try {
            $fragment = FragmentValidator::validate($this->views->resolve($result, $component));
        } catch (InvalidFragment $e) {
            return $this->invalidFragment($e, $class, $method);
        }

        $answer = [
            'protocol' => self::PROTOCOL,
            'region' => $payload['region'],
            ...($version === null ? [] : ['version' => $version]),
            'fragment' => $fragment,
        ];

        // A screen's chrome travels as its own part, checked on its own.
        if ($component instanceof ServerScreen) {
            try {
                $answer['chrome'] = (object) ChromeValidator::validate($this->views->chrome($component->chrome(), $component));
            } catch (InvalidFragment $e) {
                return $this->invalidFragment($e, $class, 'chrome');
            }
        }

        // An action's answer is the region's fragment, so it follows the region's choice.
        $request->attributes->set(SecureRequest::COMPRESS, $metadata->compresses($payload['region']));

        return response()->json($answer, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The region's version token (protocol §8.1): what the push counters
     * this region would watch on a stream with this state say now. A
     * device sends it back when it subscribes, and the stream skips the
     * region's first fragment while it still matches.
     *
     * The channels come from the component as the request hydrated it,
     * before any action runs, which is the state the device subscribes
     * with. The push store being down only costs the token. The device
     * then gets the region again when its stream opens.
     *
     * @param  array{component: string, region: string, state: array<string, mixed>, platform: string}  $payload
     */
    protected function version(ServerComponent $component, array $payload): ?string
    {
        $channels = $this->channelsOf($component);

        try {
            $snapshot = $this->versions->snapshot($payload['component'], [$payload['region']], $channels);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return PushVersions::token($payload['component'], $payload['region'], $channels, $snapshot);
    }

    /**
     * @return array{component: string, region: string, state: array<string, mixed>, platform: string, action: array{call: string, args: list<mixed>}|null}
     */
    protected function payload(Request $request): array
    {
        $body = $this->body($request);
        $component = $this->component($body['component'] ?? null);
        $region = $this->regionName($body['region'] ?? null);
        $state = $this->state($body['state'] ?? null);
        $platform = $this->platform($body['platform'] ?? null);

        $action = array_key_exists('action', $body) ? $this->action($body['action']) : null;

        return compact('component', 'region', 'state', 'platform', 'action');
    }

    /** @return array{call: string, args: list<mixed>} */
    protected function action(mixed $action): array
    {
        if (! is_array($action) || array_is_list($action)) {
            throw new InvalidRequest('action must be an object.');
        }

        $extra = array_diff(array_map('strval', array_keys($action)), ['call', 'args', 'target']);
        if ($extra !== []) {
            throw new InvalidRequest('action has unknown fields.');
        }

        if (array_key_exists('target', $action) && $action['target'] !== 'server') {
            throw new InvalidRequest('action.target must be "server" when present. Client actions run on the device.');
        }

        if (! array_key_exists('call', $action) || ! array_key_exists('args', $action)) {
            throw new InvalidRequest('action needs both call and args.');
        }

        if ($problem = Callback::nameProblem($action['call']) ?? Callback::argsProblem($action['args'])) {
            throw new InvalidRequest("action: {$problem}");
        }

        return ['call' => $action['call'], 'args' => $action['args']];
    }
}
