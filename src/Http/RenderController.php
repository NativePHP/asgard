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
        $component->setAsgardContext($payload['region'] ?? '');
        $this->giveClientCache($component, $metadata, $payload['cache']);
        $component->setAsgardPush($payload['push']);

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

        // Only an action can call refresh(). What it asked for rides along,
        // rendered with the acting region so one cache decision covers all.
        $own = $payload['region'];
        $regions = [$own];
        $hints = [];

        if ($action) {
            $asked = $component->asgardRefreshes();
            $regions = array_values(array_unique([$own, ...$asked['regions']]));
            $hints = $asked['hints'];
        }

        $parts = $this->renderParts($component, $metadata, $payload['component'], $regions, $channels, [$own => $version]);

        if ($parts instanceof JsonResponse) {
            return $parts;
        }

        $part = $parts[$own];
        unset($parts[$own]);

        // A fresh client copy goes back as {"protocol":1,"cached":true}.
        $answer = isset($part['cached'])
            ? ['protocol' => self::PROTOCOL, 'cached' => true]
            : ['protocol' => self::PROTOCOL, 'region' => $own, ...$part];

        if ($parts !== []) {
            $answer['fragments'] = $parts;
        }

        if ($hints !== []) {
            $answer['refresh'] = $hints;
        }

        // An answer is compressed only when every region in it allows it.
        $compress = true;
        foreach ($regions as $region) {
            $compress = $compress && $metadata->compresses($region);
        }

        $request->attributes->set(SecureRequest::COMPRESS, $compress);

        return $this->respond($component, $answer);
    }

    /**
     * Several regions of one component and state in one answer, keyed by
     * region, each with its own version token. No action runs.
     *
     * @param  array{component: string, regions: list<string>, state: array<string, mixed>, cache: array<string, array{made_at: int, ttl: int}>}  $payload
     * @param  list<string>  $channels
     */
    protected function batch(Request $request, ServerComponent $component, ComponentMetadata $metadata, array $payload, array $channels): JsonResponse
    {
        $parts = $this->renderParts($component, $metadata, $payload['component'], $payload['regions'], $channels);

        if ($parts instanceof JsonResponse) {
            return $parts;
        }

        // One region that opted out keeps the whole body uncompressed.
        $compress = true;
        foreach ($payload['regions'] as $region) {
            $compress = $compress && $metadata->compresses($region);
        }

        $request->attributes->set(SecureRequest::COMPRESS, $compress);

        return $this->respond($component, [
            'protocol' => self::PROTOCOL,
            'fragments' => $parts,
        ]);
    }

    /**
     * A body names one region (`region`) or several (`regions`), never
     * both. `regions` holds 1 to 16 distinct names and cannot carry an
     * action. `region` is null for a batch, and `regions` always lists
     * what is rendered.
     *
     * `cache` lists the copies of regions the client holds. `push` is
     * whether the body said `"reason": "push"`.
     *
     * @return array{component: string, region: ?string, regions: list<string>, state: array<string, mixed>, action: array{call: string, args: list<mixed>}|null, cache: array<string, array{made_at: int, ttl: int}>, push: bool}
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

        $action = array_key_exists('action', $body) ? $this->action($body['action']) : null;

        $cache = $this->cache($body['cache'] ?? null);

        // Sent when a push made the client fetch. The client could claim it
        // falsely, but the worst that gets it is a fresh render.
        if (array_key_exists('reason', $body) && $body['reason'] !== 'push') {
            throw new InvalidRequest('reason must be "push" when present.');
        }

        $push = array_key_exists('reason', $body);

        return compact('component', 'region', 'regions', 'state', 'action', 'cache', 'push');
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
