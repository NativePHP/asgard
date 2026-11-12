<?php

namespace Native\Asgard\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Security\Wire;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;
use Native\Asgard\Support\Channels;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events for one component. Sends each subscribed region once on
 * connect, then re-renders regions whenever Asgard::push() or Asgard::to()
 * bumps a version the stream watches, until the lifetime runs out or the
 * client goes away.
 *
 * When it opens, the stream joins the channels its component's channels()
 * names, worked out once from the stream's state and the request's user.
 *
 * Every check happens before the first byte is streamed, so a bad request
 * gets the same JSON error the render endpoint would give.
 */
class StreamController
{
    use ReadsAsgardRequests;

    public const MAX_REGIONS = 16;

    public const RETRY_MS = 3000;

    public const PING_SECONDS = 15;

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
            $regions = $this->regions($body['regions'] ?? null);
            $state = $this->state($body['state'] ?? null);
            $platform = $this->platform($body['platform'] ?? null);

            if (array_key_exists('action', $body)) {
                throw new InvalidRequest('A stream does not take an action. Send actions to the render endpoint.');
            }
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Plain array lookups only, exactly as render does.
        $address = $body['component'];
        $class = $this->registry->resolve($address);
        $methods = $class ? ComponentMetadata::for($class)->regions : [];

        foreach ($regions as $region) {
            if (! isset($methods[$region])) {
                return response()->json(['error' => 'unknown_component'], 404);
            }
        }

        try {
            $channels = $this->channels($class, $platform, $state);
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Read versions before the first render, so a push that lands while
        // we render is still picked up on the first poll.
        $seen = $this->versions->snapshot($address, $regions, $channels);

        // Render everything up front so bad state or a bad fragment is a
        // normal error response, not a broken stream.
        $initial = [];
        foreach ($regions as $region) {
            try {
                $initial[$region] = $this->render($class, $methods[$region], $region, $platform, $state);
            } catch (InvalidRequest $e) {
                return $this->error(422, 'invalid_request', $e->getMessage());
            } catch (InvalidFragment $e) {
                return $this->invalidFragment($e, $class, $methods[$region]);
            }
        }

        $secure = $this->secure($request);

        return new StreamedResponse(
            fn () => $this->stream($secure, $class, $methods, $address, $regions, $channels, $platform, $state, $seen, $initial),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /** @return list<string> */
    protected function regions(mixed $regions): array
    {
        if (! is_array($regions) || ! array_is_list($regions)) {
            throw new InvalidRequest('regions must be a list of region names.');
        }

        $count = count($regions);
        if ($count < 1 || $count > self::MAX_REGIONS) {
            throw new InvalidRequest('regions must name between 1 and '.self::MAX_REGIONS.' regions.');
        }

        foreach ($regions as $i => $region) {
            $this->regionName($region, "regions[{$i}]");
        }

        if (count(array_unique($regions)) !== $count) {
            throw new InvalidRequest('regions must not repeat.');
        }

        return $regions;
    }

    /**
     * The channels this stream joins, from channels() on a component
     * hydrated like a render. The app's middleware has already run, so
     * auth() sees the request's user.
     *
     * A bad answer is a bug in the app. With app.debug on it throws. In
     * production it is reported, the bad entries are dropped and the stream
     * keeps the first 16 good ones, so the screen still works and still
     * gets pushes to everyone. Any channel it misses only means fewer
     * re-renders, never someone else's fragment.
     *
     * @param  class-string<ServerComponent>  $class
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    protected function channels(string $class, string $platform, array $state): array
    {
        $component = $this->container->make($class);
        $component->setAsgardContext('', $platform);

        Hydrator::hydrate($component, $state);

        $names = [];
        $problems = [];
        foreach ($component->channels() as $i => $channel) {
            try {
                $names[] = Channels::name($channel);
            } catch (InvalidArgumentException $e) {
                $problems[] = "[{$i}] ".$e->getMessage();
            }
        }

        $names = array_values(array_unique($names));

        if (count($names) > Channels::MAX) {
            $problems[] = 'it named '.count($names).' channels and a stream joins at most '.Channels::MAX.'.';
            $names = array_slice($names, 0, Channels::MAX);
        }

        if ($problems !== []) {
            $e = new InvalidArgumentException("{$class}::channels() returned bad channels: ".implode(' ', $problems));

            if (config('app.debug')) {
                throw $e;
            }

            report($e);
        }

        return $names;
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
     * @param  class-string<ServerComponent>  $class
     * @param  array<string, string>  $methods
     * @param  list<string>  $regions
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $state
     * @param  array{component: list<int>, regions: array<string, list<int>>}  $seen
     * @param  array<string, array{fragment: array, chrome?: object}>  $initial
     */
    protected function stream(SecureRequest $secure, string $class, array $methods, string $address, array $regions, array $channels, string $platform, array $state, array $seen, array $initial): void
    {
        $lifetime = max(1, (int) config('asgard.stream.lifetime', 300));
        $poll = max(10, (int) config('asgard.stream.poll_ms', 500));

        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            @set_time_limit($lifetime + 30);
        }

        $start = $lastPing = $this->clock->now();

        $this->write('retry: '.self::RETRY_MS."\n\n");

        $id = self::eventId($seen);
        foreach ($initial as $region => $answer) {
            $this->send($secure, $id, $region, $answer, ComponentMetadata::for($class)->compresses($region));
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
                $current = $this->versions->snapshot($address, $regions, $channels);
            } catch (Throwable $e) {
                // The push store is down. End the stream; the client reconnects.
                report($e);

                return;
            }

            $componentChanged = $current['component'] !== $seen['component'];
            $id = self::eventId($current);

            foreach ($regions as $region) {
                if (! $componentChanged && $current['regions'][$region] === $seen['regions'][$region]) {
                    continue;
                }

                try {
                    $answer = $this->render($class, $methods[$region], $region, $platform, $state);
                } catch (Throwable $e) {
                    // Headers are long gone, so there is no error response to
                    // give. Report it and leave the device on its last fragment.
                    report($e);

                    continue;
                }

                $this->send($secure, $id, $region, $answer, ComponentMetadata::for($class)->compresses($region));
            }

            $seen = $current;

            if ($now - $lastPing >= self::PING_SECONDS) {
                $this->write(": ping\n\n");
                $lastPing = $now;
            }
        }
    }

    /**
     * Counters only ever go up, so their sum does too. Informational for now.
     *
     * @param  array{component: list<int>, regions: array<string, list<int>>}  $versions
     */
    protected static function eventId(array $versions): string
    {
        return (string) (array_sum($versions['component']) + array_sum(array_map('array_sum', $versions['regions'])));
    }

    /**
     * One `fragment` event, gzipped unless the region opted out, encrypted
     * with enc_s and signed with sig_s, bound to the request that opened this
     * stream (§8.1).
     *
     * @param  array{fragment: array, chrome?: object}  $answer
     */
    protected function send(SecureRequest $secure, string $id, string $region, array $answer, bool $compress = true): void
    {
        $plaintext = json_encode(
            ['protocol' => self::PROTOCOL, 'region' => $region, ...$answer],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        // The sealed data is JSON of base64url strings, so always a single line.
        $data = Wire::sealEvent($secure->keys, $secure->session, $secure->signature, $id, 'fragment', $plaintext, null, $compress);

        $this->write("id: {$id}\nevent: fragment\ndata: {$data}\n\n");
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
