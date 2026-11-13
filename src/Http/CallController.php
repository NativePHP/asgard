<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\AnswersWithDelta;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Http\Concerns\RendersRegions;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;

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
    use AnswersWithDelta;
    use ReadsAsgardRequests;
    use RendersRegions;

    private const FIELDS = ['protocol', 'component', 'method', 'args', 'state', 'platform'];

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

        return $this->answer($component, $metadata, $payload['component'], $payload['method'], $channels, $before, $payload['platform']);
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
}
