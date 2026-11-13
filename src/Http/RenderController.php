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

class RenderController
{
    use ReadsAsgardRequests;
    use RendersRegions;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
        protected RegionViews $views,
        protected PushVersions $versions,
    ) {}

    /** At most this many regions in one batch render. */
    public const MAX_REGIONS = 16;

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

        foreach ($payload['regions'] as $region) {
            if (! isset($metadata?->regions[$region])) {
                return response()->json(['error' => 'unknown_component'], 404);
            }
        }

        // Same rule for actions: the wire `call` is only a key into the map of
        // #[Action] methods built by reflection. Anything else is unknown.
        $action = $payload['action'];
        $actionSpec = $action ? ($metadata->actions[$action['call']] ?? null) : null;

        if ($action && ($actionSpec === null || ! ActionInvoker::accepts($actionSpec, count($action['args'])))) {
            return response()->json(['error' => 'unknown_action'], 404);
        }

        $component = $this->container->make($class);
        $component->setAsgardContext($payload['region'] ?? '', $payload['platform']);

        try {
            Hydrator::hydrate($component, $payload['state']);

            // Channels and tokens come from the state as the device sent it,
            // before any action runs. They are read before the render, so a
            // push that lands meanwhile makes a token stale and the stream
            // sends that region again.
            $channels = $this->channelsOf($component);

            if ($payload['region'] === null) {
                return $this->batch($request, $component, $metadata, $payload, $channels);
            }

            $version = $this->tokens($payload['component'], [$payload['region']], $channels)[$payload['region']];

            if ($action) {
                ActionInvoker::invoke($component, $action['call'], $actionSpec, $action['args']);
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $part = $this->renderPart($component, $metadata, $payload['region'], $payload['platform']);

        if ($part instanceof JsonResponse) {
            return $part;
        }

        $answer = [
            'protocol' => self::PROTOCOL,
            'region' => $payload['region'],
            ...($version === null ? [] : ['version' => $version]),
            ...$part,
        ];

        $compress = $metadata->compresses($payload['region']);

        // Only an action can call refresh(). What it asked for rides along.
        if ($action) {
            $more = $this->refreshed($component, $metadata, $payload['component'], $channels, $payload['platform'], $payload['region']);

            if ($more instanceof JsonResponse) {
                return $more;
            }

            if ($more['fragments'] !== []) {
                $answer['fragments'] = $more['fragments'];
            }

            if ($more['refresh'] !== []) {
                $answer['refresh'] = $more['refresh'];
            }

            foreach (array_keys($more['fragments']) as $region) {
                $compress = $compress && $metadata->compresses($region);
            }
        }

        // An answer is compressed only when every region in it allows it.
        $request->attributes->set(SecureRequest::COMPRESS, $compress);

        return response()->json($answer, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Several regions of one component and state in one answer, keyed by
     * region, each with its own version token. No action runs.
     *
     * @param  array{component: string, regions: list<string>, state: array<string, mixed>, platform: string}  $payload
     * @param  list<string>  $channels
     */
    protected function batch(Request $request, ServerComponent $component, ComponentMetadata $metadata, array $payload, array $channels): JsonResponse
    {
        $parts = $this->renderParts($component, $metadata, $payload['component'], $payload['regions'], $channels, $payload['platform']);

        if ($parts instanceof JsonResponse) {
            return $parts;
        }

        // One region that opted out keeps the whole body uncompressed.
        $compress = true;
        foreach ($payload['regions'] as $region) {
            $compress = $compress && $metadata->compresses($region);
        }

        $request->attributes->set(SecureRequest::COMPRESS, $compress);

        return response()->json([
            'protocol' => self::PROTOCOL,
            'fragments' => $parts,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A body names one region (`region`) or several (`regions`), never
     * both. `regions` holds 1 to 16 distinct names and cannot carry an
     * action. `region` is null for a batch, and `regions` always lists
     * what is rendered.
     *
     * @return array{component: string, region: ?string, regions: list<string>, state: array<string, mixed>, platform: string, action: array{call: string, args: list<mixed>}|null}
     */
    protected function payload(Request $request): array
    {
        $body = $this->body($request);
        $component = $this->component($body['component'] ?? null);

        if (array_key_exists('regions', $body)) {
            if (array_key_exists('region', $body)) {
                throw new InvalidRequest('Send region or regions, not both.');
            }

            if (array_key_exists('action', $body)) {
                throw new InvalidRequest('An action names one region, not regions.');
            }

            $regions = $this->regionNames($body['regions']);
            $region = null;
        } else {
            $region = $this->regionName($body['region'] ?? null);
            $regions = [$region];
        }

        $state = $this->state($body['state'] ?? null);
        $platform = $this->platform($body['platform'] ?? null);

        $action = array_key_exists('action', $body) ? $this->action($body['action']) : null;

        return compact('component', 'region', 'regions', 'state', 'platform', 'action');
    }

    /** @return list<string> */
    protected function regionNames(mixed $regions): array
    {
        if (! is_array($regions) || ! array_is_list($regions) || $regions === [] || count($regions) > self::MAX_REGIONS) {
            throw new InvalidRequest('regions must be a list of 1 to '.self::MAX_REGIONS.' region names.');
        }

        foreach ($regions as $i => $region) {
            $this->regionName($region, "regions[{$i}]");
        }

        if (count(array_unique($regions)) !== count($regions)) {
            throw new InvalidRequest('regions must not repeat a name.');
        }

        return $regions;
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
