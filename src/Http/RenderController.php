<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\Hydrator;

class RenderController
{
    use ReadsAsgardRequests;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->methodNotAllowed($request)) {
            return $refused;
        }

        try {
            $payload = $this->payload($request);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
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

            if ($action) {
                ActionInvoker::invoke($component, $action['call'], $actionSpec, $action['args']);
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $result = $this->container->call([$component, $method]);

        try {
            $fragment = FragmentValidator::validate($result);
        } catch (InvalidFragment $e) {
            return $this->invalidFragment($e, $class, $method);
        }

        return response()->json([
            'protocol' => self::PROTOCOL,
            'region' => $payload['region'],
            'fragment' => $fragment,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{component: string, region: string, state: array<string, mixed>, platform: string, action: array{call: string, args: list<mixed>}|null}
     */
    protected function payload(Request $request): array
    {
        $body = $this->body($request);
        $component = $body['component'];
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
