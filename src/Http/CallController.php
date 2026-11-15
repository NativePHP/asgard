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
 * Runs the server half of a device's `#[Server]` method and answers with
 * the props it changed. The method on this side must be a `#[ServerAction]`,
 * the same allowlist a fragment's tap or press goes through.
 *
 *     → {"protocol":1,"component":"app.native.screens.home","method":"roll","args":[],"state":{…}}
 *     ← {"protocol":1,"delta":{"roll":4}}
 *
 * The component is hydrated from the state the device sent, exactly as a
 * render hydrates it. The method runs. Then every public prop is compared
 * with its value straight after hydration, by its serialize() form, and
 * only the ones that differ go back. The device applies each one only if
 * its own value has not moved since it sent the call.
 *
 * A call changes state, so its signature must carry a nonce, like an
 * action. SealAsgardMessages refuses one without a nonce, or with a nonce it
 * has seen before, before it decrypts the body.
 *
 * Nothing is rendered unless the request names regions in `regions`, or
 * the method calls refresh(). Regions of this component named either way
 * are rendered once the method has run, together, before the delta is
 * worked out, and travel in `fragments`, keyed by region. Other components
 * refresh() names travel as hints in `refresh`:
 *
 *     → {"protocol":1,"component":"…","method":"roll","args":[],"state":{…},
 *        "read_only":["platform"],"regions":["stats"]}
 *     ← {"protocol":1,"delta":{"roll":4},
 *        "fragments":{"stats":{"version":"…","fragment":{…}}},
 *        "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
 */
class CallController
{
    use AnswersWithDelta;
    use ReadsAsgardRequests;
    use RendersRegions;

    /** Any other member is refused (§5.3). */
    private const FIELDS = ['protocol', 'component', 'method', 'args', 'state', 'read_only', 'regions', 'cache'];

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
        } catch (TooManyRegions $e) {
            return $this->error(422, 'too_many_regions', $e->getMessage());
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Plain array lookups only. The wire strings never name a class or method.
        $class = $this->registry->resolve($payload['component']);

        if ($class === null) {
            return response()->json(['error' => 'unknown_component'], 404);
        }

        $metadata = ComponentMetadata::for($class);
        $spec = $metadata->serverActions[$payload['method']] ?? null;

        if ($spec === null || ! ActionInvoker::accepts($spec, count($payload['args']))) {
            return response()->json(['error' => 'unknown_method'], 404);
        }

        if ($unknown = $this->unknownRegion($metadata, $payload['regions'])) {
            return $unknown;
        }

        $component = $this->container->make($class);
        $component->setAsgardContext('');
        $this->giveClientCache($component, $metadata, $payload['cache']);

        try {
            Hydrator::hydrate($component, $payload['state']);
            $before = $this->fingerprints($component);

            // From the state the device sent, as a render works them out.
            // Tokens are read before the method, so a push that lands
            // meanwhile makes them stale and the stream sends the region again.
            $channels = $this->channelsOf($component);
            $tokens = $payload['regions'] === [] ? [] : $this->tokens($payload['component'], $payload['regions'], $channels);

            ActionInvoker::invoke($component, $payload['method'], $spec, $payload['args'], 'args');
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        return $this->answer($component, $metadata, $payload['component'], $payload['method'], $channels, $before, $payload['state'], $payload['read_only'], $payload['regions'], $tokens);
    }

    /**
     * @return array{component: string, method: string, args: list<mixed>, state: array<string, mixed>, read_only: list<string>, regions: list<string>, cache: array<string, array{made_at: int, ttl: int}>}
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

        $regions = $this->regionList($body['regions'] ?? [], true);
        $state = $this->state($body['state'] ?? null);

        return [
            'component' => $this->component($body['component'] ?? null),
            'method' => $body['method'],
            'args' => $body['args'],
            'state' => $state,
            'read_only' => $this->readOnly($body['read_only'] ?? null, $state),
            'regions' => $regions,
            'cache' => $this->cache($body['cache'] ?? null),
        ];
    }
}
