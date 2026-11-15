<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Exceptions\TooManyRegions;
use Native\Asgard\Http\Concerns\AnswersWithDelta;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Http\Concerns\RendersRegions;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;

/**
 * Renders regions, and runs a tap's action first when the body carries one.
 *
 *     → {"protocol":1,"component":"…","regions":["greeting"],"state":{…},"read_only":["platform"]}
 *     ← {"protocol":1,"delta":{},"fragments":{"greeting":{…}}}
 *
 * Every answer carries `delta`, after the regions are rendered, so the
 * fragments always match it. An action's delta is a call's: the props it
 * or the render changed, every #[Computed] value and the `asgard.state`
 * keys. A plain render's is only what the device now holds wrongly, so it
 * is usually `{}` (AnswersWithDelta).
 *
 * An answer is compressed only when its delta is empty and every region in
 * it allows compression. A delta mixes the server's values with text the
 * device chose, which a region's opt-out cannot see (§7.4).
 */
class RenderController
{
    use AnswersWithDelta;
    use ReadsAsgardRequests;
    use RendersRegions;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
        protected RegionViews $views,
        protected PushVersions $versions,
    ) {}

    /** The default for asgard.max_regions. */
    public const MAX_REGIONS = 16;

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request);
        } catch (TooManyRegions $e) {
            return $this->error(422, 'too_many_regions', $e->getMessage());
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
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
        // #[ServerAction] methods built by reflection. Anything else is unknown.
        $action = $payload['action'];
        $actionSpec = $action ? ($metadata->serverActions[$action['call']] ?? null) : null;

        if ($action && ($actionSpec === null || ! ActionInvoker::accepts($actionSpec, count($action['args'])))) {
            return response()->json(['error' => 'unknown_action'], 404);
        }

        // An action names exactly one region, its own.
        $own = $action ? $payload['regions'][0] : null;
        $regions = $payload['regions'];
        $tokens = [];
        $hints = [];

        $component = $this->container->make($class);
        $component->setAsgardContext($own ?? '');
        $this->giveClientCache($component, $metadata, $payload['cache']);
        $component->setAsgardPush($payload['push']);

        try {
            Hydrator::hydrate($component, $payload['state']);
            $before = $this->fingerprints($component);

            // Channels and tokens come from the state as the device sent it,
            // before any action runs. They are read before the render, so a
            // push that lands meanwhile makes a token stale and the stream
            // sends that region again.
            $channels = $this->channelsOf($component);

            if ($action) {
                $tokens = $this->tokens($payload['component'], [$own], $channels);

                ActionInvoker::invoke($component, $action['call'], $actionSpec, $action['args']);

                // What refresh() asked for rides along, rendered with the
                // action's own region so one cache decision covers all. A
                // region named twice renders once.
                $asked = $component->asgardRefreshes();
                $regions = array_values(array_unique([$own, ...$asked['regions']]));
                $hints = $asked['hints'];
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $parts = $this->renderParts($component, $metadata, $payload['component'], $regions, $channels, $tokens);

        if ($parts instanceof JsonResponse) {
            return $parts;
        }

        $where = $action
            ? $class.'::'.$action['call'].'()'
            : $class.' rendering ['.implode(', ', $regions).']';

        $delta = $this->stateDelta($component, $metadata, $before, $payload['state'], $payload['read_only'], $action !== null, $where);

        if ($delta instanceof JsonResponse) {
            return $delta;
        }

        $answer = ['protocol' => self::PROTOCOL, 'delta' => (object) $delta, 'fragments' => $parts];

        if ($hints !== []) {
            $answer['refresh'] = $hints;
        }

        // An answer is compressed only when every region in it allows it, so
        // a region that opted out is never in a compressed body, and only
        // when the delta is empty, since a delta can mix the server's values
        // with text the device chose (§7.4).
        $compress = $delta === [];
        foreach ($regions as $region) {
            $compress = $compress && $metadata->compresses($region);
        }

        $request->attributes->set(SecureRequest::COMPRESS, $compress);

        return $this->respond($component, $answer);
    }

    /**
     * A body names the regions it wants in `regions`, a list even for one.
     * A body with an `action` names exactly one, the action's own region.
     *
     * `cache` lists the copies of regions the client holds. `push` is
     * whether the body said `"reason": "push"`.
     *
     * @return array{component: string, regions: list<string>, state: array<string, mixed>, read_only: list<string>, action: array{call: string, args: list<mixed>}|null, cache: array<string, array{made_at: int, ttl: int}>, push: bool}
     */
    protected function payload(Request $request): array
    {
        $body = $this->body($request);
        $component = $this->component($body['component'] ?? null);

        if (array_key_exists('region', $body)) {
            throw new InvalidRequest('region is not a field. Send regions, a list even for one region.');
        }

        $regions = $this->regionList($body['regions'] ?? null);

        $state = $this->state($body['state'] ?? null);
        $read_only = $this->readOnly($body['read_only'] ?? null, $state);

        $action = array_key_exists('action', $body) ? $this->action($body['action']) : null;

        if ($action !== null && count($regions) !== 1) {
            throw new InvalidRequest('A request with an action names exactly one region in regions.');
        }

        $cache = $this->cache($body['cache'] ?? null);

        // Sent when a push made the client fetch. The client could claim it
        // falsely, but the worst that gets it is a fresh render.
        if (array_key_exists('reason', $body) && $body['reason'] !== 'push') {
            throw new InvalidRequest('reason must be "push" when present.');
        }

        $push = array_key_exists('reason', $body);

        return compact('component', 'regions', 'state', 'read_only', 'action', 'cache', 'push');
    }

    /**
     * The most regions one request may name, from asgard.max_regions.
     */
    public static function maxRegions(): int
    {
        return max(1, (int) config('asgard.max_regions', self::MAX_REGIONS));
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
