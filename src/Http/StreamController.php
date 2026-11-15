<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ClientCache;
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
 * A region moved by Asgard::patch() gets one `patch` event per patch
 * instead, with the version before and after it, when the push store's
 * patch log covers every step since the last event and nothing else moved
 * it. Otherwise it gets the signal or fragment as above. On connect a
 * device a few patches behind gets those patches, when the log still holds
 * every one since the version it sent. Any other device behind the current
 * version gets the signal or fragment.
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

    /** How long a missing newest patch log entry is waited for, by default. */
    public const PATCH_WAIT_MS = 250;

    /**
     * connection_aborted() only notices a dead client on a write, so this
     * is also how soon a worker held by a gone device is let go.
     */
    public const PING_SECONDS = 5;

    /**
     * Times are float seconds, so a poll that lands exactly on a tick or on
     * the end of the lifetime can read a hair short of it. Within this many
     * milliseconds counts as there.
     */
    protected const MARGIN_MS = 0.001;

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
                $subscriptions[$id]['channels'] = $this->channels($subscription['class'], $subscription['state']);
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
        $elements = $this->secure($request)->elements;
        $initial = [];
        $catchUp = [];
        foreach ($subscriptions as $id => $subscription) {
            foreach ($subscription['regions'] as $region) {
                $version = PushVersions::token($subscription['component'], $region, $subscription['channels'], $seen[$id]);
                $held = $subscription['versions'][$region] ?? null;

                if ($held === $version) {
                    continue;
                }

                if ($held !== null && ($patches = $this->catchUp($subscription, $region, $held, $seen[$id], $elements)) !== null) {
                    $catchUp[] = [$id, $region, ...$patches];

                    continue;
                }

                try {
                    $answer = $this->shared($subscription, $region, $version, $elements);
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
            fn () => $this->stream($secure, $subscriptions, $watches, $seen, $initial, $catchUp),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The patches that take a region from the version a device holds to the
     * current one, when the device is only a few patches behind: the counters
     * behind its version, the steps and their log entries. Null sends the
     * signal or fragment instead.
     *
     * A version token is a digest, so the counters behind it are found by
     * trying each region counter a step at a time back, at most as far as
     * the log keeps. Only one counter may have moved, as in a poll, and
     * every entry must be a patch this client can draw. An entry not
     * written yet counts as missing: the device gets the whole region, as it
     * did before.
     *
     * @param  array{component: string, channels: list<string>}  $subscription
     * @param  array{component: list<int>, regions: array<string, list<int>>}  $current
     * @param  list<string>  $elements
     * @return array{list<int>, array{index: int, keys: list<string>}, array<string, mixed>}|null
     */
    protected function catchUp(array $subscription, string $region, string $held, array $current, array $elements): ?array
    {
        $cap = $this->versions->patchLog();
        $after = $current['regions'][$region] ?? [];
        $before = null;

        foreach ($after as $index => $value) {
            for ($back = 1; $back <= min($cap, $value) && $before === null; $back++) {
                $candidate = $after;
                $candidate[$index] = $value - $back;

                $token = PushVersions::token($subscription['component'], $region, $subscription['channels'], [
                    'component' => $current['component'],
                    'regions' => [$region => $candidate],
                ]);

                if ($token === $held) {
                    $before = $candidate;
                }
            }
        }

        if ($before === null) {
            return null;
        }

        $steps = $this->patchSteps($subscription, $region, $before, $after, $cap);

        if ($steps === null) {
            return null;
        }

        try {
            $log = $this->versions->entries($steps['keys']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        foreach ($steps['keys'] as $key) {
            $entry = $log[$key] ?? null;

            if (! is_array($entry) || ! is_string($entry['patch'] ?? null)
                || FragmentValidator::unknownTypes((array) ($entry['types'] ?? []), $elements) !== []) {
                return null;
            }
        }

        return [$before, $steps, $log];
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

            // Members this version does not know are ignored (§5.3). Only an
            // action is refused, as at the top level (§8.1).
            if (array_key_exists('action', $subscription)) {
                throw new InvalidRequest("{$field} must not carry an action.");
            }

            $id = $subscription['id'] ?? null;
            if (! is_string($id) || ! preg_match(self::SUBSCRIPTION_PATTERN, $id)) {
                throw new InvalidRequest("{$field}.id must be 1 to 32 characters of A-Z, a-z, 0-9, _ and -.");
            }

            if (isset($checked[$id])) {
                throw new InvalidRequest('subscription ids must not repeat.');
            }

            $regions = $this->regions($subscription['regions'] ?? null, "{$field}.regions");

            $state = $this->state($subscription['state'] ?? null);

            // Checked like everywhere else that takes state. A stream sends
            // no delta, so there is nothing more to do with it.
            $this->readOnly($subscription['read_only'] ?? null, $state, "{$field}.read_only");

            $checked[$id] = [
                'component' => $this->component($subscription['component'] ?? null, "{$field}.component"),
                'regions' => $regions,
                'state' => $state,
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
     * The element types the stream's client advertised are part of the
     * cache key, since they decide which fragments are allowed.
     *
     * @param  array{component: string, state: array<string, mixed>, class: class-string<ServerComponent>, methods: array<string, string>}  $subscription
     * @param  list<string>  $elements
     * @return array{made_at: int, max_age?: int, fragment: array, chrome?: object}|null
     */
    protected function shared(array $subscription, string $region, string $version, array $elements): ?array
    {
        if (! ComponentMetadata::for($subscription['class'])->shares($region)) {
            return null;
        }

        // made_at is part of what the push store keeps, so every device sent
        // this render gets the time it was really made.
        $answer = $this->versions->shared(
            $subscription['component'], $region, $subscription['state'], $version,
            fn () => ['made_at' => ClientCache::nowMs(), ...$this->asGuest(fn () => $this->render($subscription['class'], $subscription['methods'][$region], $region, $subscription['state'], $elements))],
            $elements,
        );

        $stamp = ComponentMetadata::for($subscription['class'])->stamp($region, (int) ($answer['made_at'] ?? ClientCache::nowMs()));
        $answer = [...$stamp, ...array_diff_key($answer, $stamp)];

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
     * @param  list<string>  $elements
     * @return array{fragment: array<string, mixed>|list<array<string, mixed>>, chrome?: object}
     */
    protected function render(string $class, string $method, string $region, array $state, array $elements): array
    {
        $component = $this->container->make($class);
        $component->setAsgardContext($region);

        Hydrator::hydrate($component, $state);

        $views = $this->container->make(RegionViews::class);

        $answer = ['fragment' => FragmentValidator::validate(
            $views->resolve($this->container->call([$component, $method]), $component),
            $elements,
        )];

        if ($component instanceof ServerScreen) {
            $answer['chrome'] = (object) ChromeValidator::validate($views->chrome($component->chrome(), $component), $elements);
        }

        return $answer;
    }

    /**
     * @param  array<string, array{component: string, regions: list<string>, state: array<string, mixed>, class: class-string<ServerComponent>, methods: array<string, string>, channels: list<string>}>  $subscriptions
     * @param  array<string, array{address: string, regions: list<string>, channels: list<string>}>  $watches
     * @param  array<string, array{component: list<int>, regions: array<string, list<int>>}>  $seen
     * @param  list<array{string, string, string, array{fragment: array, chrome?: object}|null}>  $initial
     * @param  list<array{string, string, list<int>, array{index: int, keys: list<string>}, array<string, mixed>}>  $catchUp
     */
    protected function stream(SecureRequest $secure, array $subscriptions, array $watches, array $seen, array $initial, array $catchUp = []): void
    {
        $lifetime = max(1, (int) config('asgard.stream.lifetime', 300));
        $poll = max(10, (int) config('asgard.stream.poll_ms', 500));
        $wait = max(0, (int) config('asgard.stream.patch_wait_ms', self::PATCH_WAIT_MS));
        $cap = $this->versions->patchLog();

        // Per subscription and region, the newest log entry found missing
        // and when it was first missed. A patch's counter is bumped just
        // before its entry is written, so a poll can land between the two,
        // and the process writing it can stall there for a while. Once
        // patch_wait_ms has gone by (and at least one more poll), a missing
        // entry really is gone.
        $waiting = [];

        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit($lifetime + 30);
        }

        $start = $lastPing = $this->clock->now();

        $this->write('retry: '.self::RETRY_MS."\n\n");

        $eventId = self::eventId($seen);
        foreach ($initial as [$id, $region, $version, $answer]) {
            $this->send($secure, $eventId, $id, $region, $version, $answer, ComponentMetadata::for($subscriptions[$id]['class'])->compresses($region));
        }

        // A device a few patches behind gets them now, every entry already read.
        foreach ($catchUp as [$id, $region, $before, $steps, $log]) {
            $none = [];
            $this->sendPatches($secure, $eventId, $id, $region, $subscriptions[$id], $seen[$id], $before, $steps, $log, $none, ComponentMetadata::for($subscriptions[$id]['class'])->compresses($region), $start);
        }

        // Polls run on a fixed schedule, at start + n * poll_ms, so the work
        // a poll does comes out of the sleep before the next one instead of
        // adding to it.
        $tick = 0;

        while (! $this->clock->aborted()) {
            $elapsed = ($this->clock->now() - $start) * 1000;
            $remaining = $lifetime * 1000 - $elapsed;
            if ($remaining <= self::MARGIN_MS) {
                return;
            }

            $tick = self::nextTick($tick, $elapsed, $poll);

            $this->clock->sleep((int) max(1, min(ceil($tick * $poll - $elapsed - self::MARGIN_MS), ceil($remaining - self::MARGIN_MS))));

            $now = $this->clock->now();
            if ($this->clock->aborted() || ($now - $start) * 1000 >= $lifetime * 1000 - self::MARGIN_MS) {
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

            // First every region that moved, and the log entries that could
            // carry it as patches, so they are all read in one many() call.
            $moved = [];
            $wanted = [];
            foreach ($subscriptions as $id => $subscription) {
                $componentChanged = $current[$id]['component'] !== $seen[$id]['component'];

                foreach ($subscription['regions'] as $region) {
                    if (! $componentChanged && $current[$id]['regions'][$region] === $seen[$id]['regions'][$region]) {
                        continue;
                    }

                    $steps = $componentChanged ? null : $this->patchSteps($subscription, $region, $seen[$id]['regions'][$region], $current[$id]['regions'][$region], $cap);
                    if ($steps !== null) {
                        array_push($wanted, ...$steps['keys']);
                    }

                    $moved[] = [$id, $region, $steps];
                }
            }

            try {
                $log = $wanted === [] ? [] : $this->versions->entries($wanted);
            } catch (Throwable $e) {
                report($e);

                return;
            }

            $next = $current;

            foreach ($moved as [$id, $region, $steps]) {
                $subscription = $subscriptions[$id];
                $compress = ComponentMetadata::for($subscription['class'])->compresses($region);

                if ($steps !== null) {
                    $sent = $this->sendPatches($secure, $eventId, $id, $region, $subscription, $current[$id], $seen[$id]['regions'][$region], $steps, $log, $waiting, $compress, $now, $wait);

                    if ($sent !== null) {
                        $next[$id]['regions'][$region] = $sent;

                        continue;
                    }
                }

                unset($waiting[$id][$region]);

                $version = PushVersions::token($subscription['component'], $region, $subscription['channels'], $current[$id]);

                try {
                    $answer = $this->shared($subscription, $region, $version, $secure->elements);
                } catch (Throwable $e) {
                    // Headers are long gone, so there is no error response to
                    // give. Report it and leave the device on its last fragment.
                    report($e);

                    continue;
                }

                $this->send($secure, $eventId, $id, $region, $version, $answer, $compress);
            }

            $seen = $next;

            if ($now - $lastPing >= self::PING_SECONDS) {
                $this->write(": ping\n\n");
                $lastPing = $now;
            }
        }
    }

    /**
     * Whether a region's move could be patches: exactly one of its counters
     * moved, forward, by no more than the log keeps. Then the counter's
     * index in the region's list and the log keys for each step. Null means
     * signal or send the fragment, as for any push.
     *
     * Two counters moving at once (a patch to everyone and one to a channel,
     * say) gives no order between their entries, so that falls back too.
     *
     * @param  array{component: string, regions: list<string>, channels: list<string>}  $subscription
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @return array{index: int, keys: list<string>}|null
     */
    protected function patchSteps(array $subscription, string $region, array $before, array $after, int $cap): ?array
    {
        if ($cap < 1 || count($before) !== count($after)) {
            return null;
        }

        $index = null;
        foreach ($after as $i => $value) {
            if ($value !== $before[$i]) {
                if ($index !== null) {
                    return null;
                }
                $index = $i;
            }
        }

        if ($index === null || $after[$index] <= $before[$index] || $cap < $after[$index] - $before[$index]) {
            return null;
        }

        $counter = $index === 0
            ? PushVersions::regionKey($subscription['component'], $region)
            : PushVersions::channelRegionKey($subscription['channels'][$index - 1], $subscription['component'], $region);

        $keys = [];
        for ($value = $before[$index] + 1; $value <= $after[$index]; $value++) {
            $keys[] = PushVersions::logKey($counter, $value);
        }

        return ['index' => $index, 'keys' => $keys];
    }

    /**
     * Send a region's move as one `patch` event per log entry, in order.
     * Gives the region's counters as far as they were sent, or null to fall
     * back to a signal or fragment: a plain push among the steps, an entry
     * that is gone, or a patch with an element type this client did not
     * advertise. Nothing is sent before a fall back is known.
     *
     * Missing entries at the end hold the region back for up to
     * patch_wait_ms, and at least one poll (see $waiting), sending the ones
     * before them.
     *
     * @param  array{component: string, channels: list<string>}  $subscription
     * @param  array{component: list<int>, regions: array<string, list<int>>}  $current
     * @param  list<int>  $before
     * @param  array{index: int, keys: list<string>}  $steps
     * @param  array<string, mixed>  $log
     * @param  array<string, array<string, array{value: int, since: float}>>  $waiting
     * @return list<int>|null
     */
    protected function sendPatches(SecureRequest $secure, string $eventId, string $id, string $region, array $subscription, array $current, array $before, array $steps, array $log, array &$waiting, bool $compress, float $now = 0.0, int $wait = 0): ?array
    {
        $index = $steps['index'];
        $entries = [];

        foreach ($steps['keys'] as $step => $key) {
            $entry = $log[$key] ?? null;
            $value = $before[$index] + $step + 1;

            if ($entry === null) {
                $rest = array_slice($steps['keys'], $step);
                $allMissing = array_filter($rest, fn ($k) => ($log[$k] ?? null) !== null) === [];

                if (! $allMissing) {
                    return null;
                }

                $since = ($waiting[$id][$region]['value'] ?? null) === $value
                    ? $waiting[$id][$region]['since']
                    : null;

                // Missed on an earlier poll, and waited long enough.
                if ($since !== null && ($now - $since) * 1000 >= $wait - self::MARGIN_MS) {
                    return null;
                }

                if ($since === null) {
                    $waiting[$id][$region] = ['value' => $value, 'since' => $now];
                }

                break;
            }

            if (! is_array($entry) || ! is_string($entry['patch'] ?? null)
                || FragmentValidator::unknownTypes((array) ($entry['types'] ?? []), $secure->elements) !== []) {
                return null;
            }

            $entries[] = $entry['patch'];

            if (($waiting[$id][$region]['value'] ?? null) === $value) {
                unset($waiting[$id][$region]);
            }
        }

        $counters = $before;
        $snapshot = ['component' => $current['component'], 'regions' => [$region => $counters]];
        $from = PushVersions::token($subscription['component'], $region, $subscription['channels'], $snapshot);

        foreach ($entries as $patch) {
            $snapshot['regions'][$region][$index]++;
            $version = PushVersions::token($subscription['component'], $region, $subscription['channels'], $snapshot);

            $this->sendPatch($secure, $eventId, $id, $region, $from, $version, $patch, $compress);

            $from = $version;
        }

        return $snapshot['regions'][$region];
    }

    /**
     * A `patch` event. The patch is JSON already, rendered once at push
     * time, so it is spliced in rather than decoded and encoded again.
     */
    protected function sendPatch(SecureRequest $secure, string $id, string $subscription, string $region, string $from, string $version, string $patch, bool $compress): void
    {
        $head = json_encode(
            ['protocol' => self::PROTOCOL, 'subscription' => $subscription, 'region' => $region, 'from' => $from, 'version' => $version],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $plaintext = substr($head, 0, -1).',"patch":'.$patch.'}';

        $data = Wire::sealEvent($secure->keys, $secure->session, $secure->signature, $id, 'patch', $plaintext, null, $compress);

        $this->write("id: {$id}\nevent: patch\ndata: {$data}\n\n");
    }

    /**
     * The next poll's tick, counted in poll_ms from the stream's start. A
     * poll that ran over one or more ticks skips to the next one still
     * ahead rather than polling again at once to catch up.
     */
    protected static function nextTick(int $tick, float $elapsedMs, int $pollMs): int
    {
        return max($tick + 1, intdiv((int) floor($elapsedMs + self::MARGIN_MS), $pollMs) + 1);
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
