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
use Native\Asgard\Security\Wire;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\GuestGuard;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events for one device. The request lists subscriptions, one
 * per component instance the device has on screen: an id the device chose,
 * a component address, its regions, the state they were rendered for and
 * the version token the device holds for each region.
 *
 * On connect it tells the device about each region whose current token
 * differs from the device's. Then it does the same whenever Asgard::push()
 * or Asgard::to() bumps a counter a subscription watches, until the
 * lifetime runs out or the client goes away. Every event names its
 * subscription.
 *
 * For most regions that is a `signal`: the region and its new version, no
 * fragment. The device fetches the region through render, with its own
 * user. A region marked shared gets a `fragment` instead, rendered as a
 * guest once per state and version and reused by every stream through the
 * push store.
 *
 * Each subscription joins the channels its component's channels() names,
 * worked out once from its own state and the request's user. One poll
 * reads every counter of every subscription in one many() call.
 *
 * An open stream holds one PHP worker for its whole lifetime, which is why
 * a device opens one stream, not one per component.
 *
 * Every check happens before the first byte is streamed, so a bad request
 * gets the same JSON error the render endpoint would give.
 */
class StreamController
{
    use ReadsAsgardRequests;

    public const MAX_SUBSCRIPTIONS = 16;

    public const MAX_REGIONS = 16;

    public const SUBSCRIPTION_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/D';

    public const VERSION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    public const RETRY_MS = 3000;

    /**
     * connection_aborted() only notices a dead client on a write, so this
     * is also how soon a worker held by a gone device is let go.
     */
    public const PING_SECONDS = 5;

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
        protected PushVersions $versions,
        protected StreamClock $clock,
    ) {}

    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        try {
            $body = $this->body($request);

            if (array_key_exists('action', $body)) {
                throw new InvalidRequest('A stream does not take an action. Send actions to the render endpoint.');
            }

            $platform = $this->platform($body['platform'] ?? null);
            $subscriptions = $this->subscriptions($body['subscriptions'] ?? null);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Plain array lookups only, exactly as render does.
        foreach ($subscriptions as $id => $subscription) {
            $class = $this->registry->resolve($subscription['component']);
            $methods = $class ? ComponentMetadata::for($class)->regions : [];

            foreach ($subscription['regions'] as $region) {
                if (! isset($methods[$region])) {
                    return response()->json(['error' => 'unknown_component'], 404);
                }
            }

            $subscriptions[$id] += ['class' => $class, 'methods' => $methods];
        }

        try {
            foreach ($subscriptions as $id => $subscription) {
                $subscriptions[$id]['channels'] = $this->channels($subscription['class'], $platform, $subscription['state']);
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $watches = self::watches($subscriptions);

        // Read the counters before the first render, so a push that lands
        // while we render is still picked up on the first poll.
        $seen = $this->versions->snapshots($watches);

        // Shared regions are rendered up front, so a bad fragment is a normal
        // error response, not a broken stream. Bad state was already caught
        // when channels() hydrated each subscription. A region the device
        // already holds at the current version is left out.
        $initial = [];
        foreach ($subscriptions as $id => $subscription) {
            foreach ($subscription['regions'] as $region) {
                $version = PushVersions::token($subscription['component'], $region, $subscription['channels'], $seen[$id]);

                if (($subscription['versions'][$region] ?? null) === $version) {
                    continue;
                }

                try {
                    $answer = $this->shared($subscription, $region, $version, $platform);
                } catch (InvalidRequest $e) {
                    return $this->error(422, 'invalid_request', $e->getMessage());
                } catch (InvalidFragment $e) {
                    return $this->invalidFragment($e, $subscription['class'], $subscription['methods'][$region]);
                }

                $initial[] = [$id, $region, $version, $answer];
            }
        }

        $secure = $this->secure($request);

        return new StreamedResponse(
            fn () => $this->stream($secure, $subscriptions, $watches, $platform, $seen, $initial),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The subscriptions, keyed by their id, each checked as a render
     * request would be. No component is looked up yet.
     *
     * @return array<string, array{component: string, regions: list<string>, state: array<string, mixed>, versions: array<string, string>}>
     */
    protected function subscriptions(mixed $subscriptions): array
    {
        if (! is_array($subscriptions) || ! array_is_list($subscriptions)) {
            throw new InvalidRequest('subscriptions must be a list.');
        }

        $count = count($subscriptions);
        if ($count < 1 || $count > self::MAX_SUBSCRIPTIONS) {
            throw new InvalidRequest('subscriptions must hold between 1 and '.self::MAX_SUBSCRIPTIONS.' subscriptions.');
        }

        $checked = [];
        foreach ($subscriptions as $i => $subscription) {
            $field = "subscriptions[{$i}]";

            if (! is_array($subscription) || array_is_list($subscription)) {
                throw new InvalidRequest("{$field} must be an object.");
            }

            $extra = array_diff(array_map('strval', array_keys($subscription)), ['id', 'component', 'regions', 'state', 'versions']);
            if ($extra !== []) {
                throw new InvalidRequest("{$field} has unknown fields.");
            }

            $id = $subscription['id'] ?? null;
            if (! is_string($id) || ! preg_match(self::SUBSCRIPTION_PATTERN, $id)) {
                throw new InvalidRequest("{$field}.id must be 1 to 32 characters of A-Z, a-z, 0-9, _ and -.");
            }

            if (isset($checked[$id])) {
                throw new InvalidRequest('subscription ids must not repeat.');
            }

            $regions = $this->regions($subscription['regions'] ?? null, "{$field}.regions");

            $checked[$id] = [
                'component' => $this->component($subscription['component'] ?? null, "{$field}.component"),
                'regions' => $regions,
                'state' => $this->state($subscription['state'] ?? null),
                'versions' => $this->versions($subscription['versions'] ?? null, $regions, "{$field}.versions"),
            ];
        }

        return $checked;
    }

    /** @return list<string> */
    protected function regions(mixed $regions, string $field = 'regions'): array
    {
        if (! is_array($regions) || ! array_is_list($regions)) {
            throw new InvalidRequest("{$field} must be a list of region names.");
        }

        $count = count($regions);
        if ($count < 1 || $count > self::MAX_REGIONS) {
            throw new InvalidRequest("{$field} must name between 1 and ".self::MAX_REGIONS.' regions.');
        }

        foreach ($regions as $i => $region) {
            $this->regionName($region, "{$field}[{$i}]");
        }

        if (count(array_unique($regions)) !== $count) {
            throw new InvalidRequest("{$field} must not repeat.");
        }

        return $regions;
    }

    /**
     * The version token the device holds per region. Optional, and only
     * for regions the subscription names.
     *
     * @param  list<string>  $regions
     * @return array<string, string>
     */
    protected function versions(mixed $versions, array $regions, string $field): array
    {
        $versions ??= [];

        if (! is_array($versions) || ($versions !== [] && array_is_list($versions))) {
            throw new InvalidRequest("{$field} must be an object.");
        }

        foreach ($versions as $region => $version) {
            if (! in_array((string) $region, $regions, true)) {
                throw new InvalidRequest("{$field} names a region the subscription does not.");
            }

            if (! is_string($version) || ! preg_match(self::VERSION_PATTERN, $version)) {
                throw new InvalidRequest("{$field}.{$region} must be a version token.");
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, array{component: string, regions: list<string>, channels: list<string>}>  $subscriptions
     * @return array<string, array{address: string, regions: list<string>, channels: list<string>}>
     */
    protected static function watches(array $subscriptions): array
    {
        return array_map(fn (array $s) => [
            'address' => $s['component'],
            'regions' => $s['regions'],
            'channels' => $s['channels'],
        ], $subscriptions);
    }

    /**
     * A shared region's answer, from the push store when another stream
     * already rendered it for this state and version. Null for a region
     * that is not shared, which the stream signals instead.
     *
     * @param  array{component: string, state: array<string, mixed>, class: class-string<ServerComponent>, methods: array<string, string>}  $subscription
     * @return array{fragment: array, chrome?: object}|null
     */
    protected function shared(array $subscription, string $region, string $version, string $platform): ?array
    {
        if (! ComponentMetadata::for($subscription['class'])->shares($region)) {
            return null;
        }

        $answer = $this->versions->shared(
            $subscription['component'], $region, $platform, $subscription['state'], $version,
            fn () => $this->asGuest(fn () => $this->render($subscription['class'], $subscription['methods'][$region], $region, $platform, $subscription['state'])),
        );

        // Chrome is a JSON object even when empty, however the store kept it.
        if (array_key_exists('chrome', $answer)) {
            $answer['chrome'] = (object) (array) $answer['chrome'];
        }

        return $answer;
    }

    /**
     * Run a render with nobody signed in. A shared region's answer is cached
     * without a user in its key, so no user may reach it: auth(), the
     * default guard and the request's user() all see a guest meanwhile.
     */
    protected function asGuest(\Closure $render): mixed
    {
        $auth = $this->container->make('auth');
        $previous = $auth->getDefaultDriver();

        $auth->shouldUse(GuestGuard::NAME);

        try {
            return $render();
        } finally {
            $auth->shouldUse($previous);
        }
    }

    /**
     * A fresh component, hydrated from the original state, every time.
     *
     * @param  class-string<ServerComponent>  $class
     *                                                A screen's answer carries its chrome too, as render's does.
     * @param  array<string, mixed>  $state
     * @return array{fragment: array<string, mixed>|list<array<string, mixed>>, chrome?: object}
     */
    protected function render(string $class, string $method, string $region, string $platform, array $state): array
    {
        $component = $this->container->make($class);
        $component->setAsgardContext($region, $platform);

        Hydrator::hydrate($component, $state);

        $views = $this->container->make(RegionViews::class);

        $answer = ['fragment' => FragmentValidator::validate(
            $views->resolve($this->container->call([$component, $method]), $component),
        )];

        if ($component instanceof ServerScreen) {
            $answer['chrome'] = (object) ChromeValidator::validate($views->chrome($component->chrome(), $component));
        }

        return $answer;
    }

    /**
     * @param  array<string, array{component: string, regions: list<string>, state: array<string, mixed>, class: class-string<ServerComponent>, methods: array<string, string>, channels: list<string>}>  $subscriptions
     * @param  array<string, array{address: string, regions: list<string>, channels: list<string>}>  $watches
     * @param  array<string, array{component: list<int>, regions: array<string, list<int>>}>  $seen
     * @param  list<array{string, string, string, array{fragment: array, chrome?: object}|null}>  $initial
     */
    protected function stream(SecureRequest $secure, array $subscriptions, array $watches, string $platform, array $seen, array $initial): void
    {
        $lifetime = max(1, (int) config('asgard.stream.lifetime', 300));
        $poll = max(10, (int) config('asgard.stream.poll_ms', 500));

        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit($lifetime + 30);
        }

        $start = $lastPing = $this->clock->now();

        $this->write('retry: '.self::RETRY_MS."\n\n");

        $eventId = self::eventId($seen);
        foreach ($initial as [$id, $region, $version, $answer]) {
            $this->send($secure, $eventId, $id, $region, $version, $answer, ComponentMetadata::for($subscriptions[$id]['class'])->compresses($region));
        }

        while (! $this->clock->aborted()) {
            $remaining = $lifetime - ($this->clock->now() - $start);
            if ($remaining <= 0) {
                return;
            }

            $this->clock->sleep((int) min($poll, ceil($remaining * 1000)));

            $now = $this->clock->now();
            if ($this->clock->aborted() || $now - $start >= $lifetime) {
                return;
            }

            try {
                $current = $this->versions->snapshots($watches);
            } catch (Throwable $e) {
                // The push store is down. End the stream; the client reconnects.
                report($e);

                return;
            }

            $eventId = self::eventId($current);

            foreach ($subscriptions as $id => $subscription) {
                $componentChanged = $current[$id]['component'] !== $seen[$id]['component'];

                foreach ($subscription['regions'] as $region) {
                    if (! $componentChanged && $current[$id]['regions'][$region] === $seen[$id]['regions'][$region]) {
                        continue;
                    }

                    $version = PushVersions::token($subscription['component'], $region, $subscription['channels'], $current[$id]);

                    try {
                        $answer = $this->shared($subscription, $region, $version, $platform);
                    } catch (Throwable $e) {
                        // Headers are long gone, so there is no error response to
                        // give. Report it and leave the device on its last fragment.
                        report($e);

                        continue;
                    }

                    $this->send($secure, $eventId, $id, $region, $version, $answer, ComponentMetadata::for($subscription['class'])->compresses($region));
                }
            }

            $seen = $current;

            if ($now - $lastPing >= self::PING_SECONDS) {
                $this->write(": ping\n\n");
                $lastPing = $now;
            }
        }
    }

    /**
     * Counters only ever go up, so their sum does too. Informational only:
     * version tokens, not this id, decide what a device is sent on connect.
     *
     * @param  array<string, array{component: list<int>, regions: array<string, list<int>>}>  $snapshots
     */
    protected static function eventId(array $snapshots): string
    {
        $sum = 0;
        foreach ($snapshots as $snapshot) {
            $sum += array_sum($snapshot['component']) + array_sum(array_map('array_sum', $snapshot['regions']));
        }

        return (string) $sum;
    }

    /**
     * One event, gzipped unless the region opted out, encrypted
     * with enc_s and signed with sig_s, bound to the request that opened this
     * stream (§8.1).
     *
     * Without an answer it is a `signal` event, which carries the version
     * alone and tells the device to fetch the region.
     *
     * @param  array{fragment: array, chrome?: object}|null  $answer
     */
    protected function send(SecureRequest $secure, string $id, string $subscription, string $region, string $version, ?array $answer, bool $compress = true): void
    {
        $event = $answer === null ? 'signal' : 'fragment';

        $plaintext = json_encode(
            ['protocol' => self::PROTOCOL, 'subscription' => $subscription, 'region' => $region, 'version' => $version, ...($answer ?? [])],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // The sealed data is JSON of base64url strings, so always a single line.
        $data = Wire::sealEvent($secure->keys, $secure->session, $secure->signature, $id, $event, $plaintext, null, $compress && $answer !== null);

        $this->write("id: {$id}\nevent: {$event}\ndata: {$data}\n\n");
    }

    protected function write(string $chunk): void
    {
        echo $chunk;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
