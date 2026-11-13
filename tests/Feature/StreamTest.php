<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\CounterScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    config()->set('asgard.stream.lifetime', 3);
    config()->set('asgard.stream.poll_ms', 500);

    $this->clock = new FakeClock;
    $this->app->instance(StreamClock::class, $this->clock);
});

const GREETING = [
    'type' => 'column',
    'class' => 'p-4 gap-2',
    'children' => [
        ['type' => 'text', 'key' => 'hello', 'props' => ['text' => 'Hello, Simon']],
        ['type' => 'text', 'props' => ['text' => 'Role: guest']],
        ['type' => 'text', 'props' => ['text' => 'Count: 0']],
        ['type' => 'text', 'props' => ['text' => 'Platform: ios']],
    ],
];

it('sends retry and a signal for each region in SSE format', function () {
    $response = $this->asgardStream(streamBody())->assertOk();

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
    expect($response->headers->getCookies())->toBe([]);

    $content = $response->streamedContent();

    // Each event is id, name and one data line, and the data is sealed: it
    // carries no plaintext fragment at all.
    expect($content)->toStartWith("retry: 3000\n\n")
        ->and($content)->toMatch('/^retry: 3000\n\n(id: 0\nevent: signal\ndata: \{"body":"[A-Za-z0-9_-]+","enc":"identity","sig":"[A-Za-z0-9_-]{43}"\}\n\n){2}$/D');

    [, $greeting, $list] = sseBlocks($content);

    // A signal carries no fragment, only what changed and its version.
    expect($greeting['data']['version'])->toMatch('/^[A-Za-z0-9_-]{16}$/')
        ->and($greeting['data'])->toBe(['protocol' => 1, 'subscription' => 's1', 'region' => 'greeting', 'version' => $greeting['data']['version']])
        ->and($list['data'])->toBe(['protocol' => 1, 'subscription' => 's1', 'region' => 'list', 'version' => $list['data']['version']]);

    // Signalling renders nothing.
    expect(HomeScreen::$calls)->toBe([]);
});

it('sends a shared region inline as a fragment', function () {
    $content = $this->asgardStream(streamBody(['component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]]))->streamedContent();

    [, $tick] = sseBlocks($content);

    expect($tick['event'])->toBe('fragment')
        ->and($tick['data'])->toBe([
            'protocol' => 1, 'subscription' => 's1', 'region' => 'tick', 'version' => $tick['data']['version'],
            'fragment' => ['type' => 'text', 'props' => ['text' => 'Count: 6']],
        ]);
});

it('accepts a real QUERY request', function () {
    $response = $this->asgardStream(streamBody(['regions' => ['greeting']]), 'QUERY')->assertOk();

    expect(sseEvents($response->streamedContent()))->toHaveCount(1);
});

it('ignores Last-Event-ID and goes by version tokens', function () {
    $response = $this->asgardStream(streamBody(), 'POST', ['Last-Event-ID' => '7'])->assertOk();

    expect(array_column(sseEvents($response->streamedContent()), 'region'))->toBe(['greeting', 'list']);
});

// ── Versions ────────────────────────────────────────

/** The version token render answers with for a region of the home screen. */
function renderedVersion(TestCase $test, string $region): string
{
    return $test->asgard(asgardBody(['region' => $region]))->assertOk()->json('version');
}

it('answers a render with the token the stream would start from', function () {
    $greeting = renderedVersion($this, 'greeting');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect($events[0]['version'])->toBe($greeting)
        ->and($greeting)->not->toBe(renderedVersion($this, 'list'));
});

it('skips the first fragment of a region whose version the device already holds', function () {
    $versions = ['greeting' => renderedVersion($this, 'greeting'), 'list' => renderedVersion($this, 'list')];

    $events = sseEvents($this->asgardStream(streamBody(['versions' => $versions]))->streamedContent());

    expect($events)->toBe([]);
});

it('sends the first fragment of a region whose version is stale or missing', function () {
    $versions = ['greeting' => renderedVersion($this, 'greeting'), 'list' => 'stale'];

    expect(array_column(sseEvents($this->asgardStream(streamBody(['versions' => $versions]))->streamedContent()), 'region'))->toBe(['list']);

    $versions = ['greeting' => renderedVersion($this, 'greeting')];

    expect(array_column(sseEvents($this->asgardStream(streamBody(['versions' => $versions]))->streamedContent()), 'region'))->toBe(['list']);
});

it('does not lose a push that lands between the render answer and the stream', function () {
    $versions = ['greeting' => renderedVersion($this, 'greeting'), 'list' => renderedVersion($this, 'list')];

    Asgard::push('app.native.screens.home', 'list');

    $events = sseEvents($this->asgardStream(streamBody(['versions' => $versions]))->streamedContent());

    expect(array_column($events, 'region'))->toBe(['list']);
});

it('keeps sending pushes after skipping the first fragments, with the new version', function () {
    $versions = ['greeting' => renderedVersion($this, 'greeting'), 'list' => renderedVersion($this, 'list')];
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home', 'list');

    $events = sseEvents($this->asgardStream(streamBody(['versions' => $versions]))->streamedContent());

    expect(array_column($events, 'region'))->toBe(['list'])
        ->and($events[0]['version'])->not->toBe($versions['list'])
        ->and($events[0]['version'])->toBe(renderedVersion($this, 'list'));
});

// ── Several subscriptions ───────────────────────────

it('carries several subscriptions on one stream, each event naming its own', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push(CounterScreen::class);

    $events = sseEvents($this->asgardStream(streamBody([], [
        subscription(['id' => 'home', 'regions' => ['greeting']]),
        subscription(['id' => 'counter', 'component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]]),
    ]))->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['subscription'], $e['region']], $events))->toBe([
        ['0', 'home', 'greeting'], ['0', 'counter', 'tick'],
        ['1', 'counter', 'tick'],
    ]);
    expect($events[2]['fragment']['props']['text'])->toBe('Count: 6');
});

it('renders each instance of one address with its own state', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push(CounterScreen::class);

    $events = sseEvents($this->asgardStream(streamBody([], [
        subscription(['id' => 'five', 'component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]]),
        subscription(['id' => 'nine', 'component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 9]]),
    ]))->streamedContent());

    expect(array_map(fn ($e) => [$e['subscription'], $e['fragment']['props']['text']], $events))->toBe([
        ['five', 'Count: 6'], ['nine', 'Count: 10'],
        ['five', 'Count: 6'], ['nine', 'Count: 10'],
    ]);
});

it('reads every counter of every subscription in one many() call per poll', function () {
    $repository = new class(new ArrayStore) extends Repository
    {
        public array $calls = [];

        public function many(array $keys)
        {
            $this->calls[] = $keys;

            return parent::many($keys);
        }
    };
    $this->app->instance(PushVersions::class, new PushVersions(new class($repository) implements Factory
    {
        public function __construct(public Repository $repository) {}

        public function store($name = null)
        {
            return $this->repository;
        }
    }));

    $this->asgardStream(streamBody([], [
        subscription(['id' => 'home']),
        subscription(['id' => 'counter', 'component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]]),
    ]))->streamedContent();

    // One read before the first render, then one per poll that did not end the stream.
    expect($repository->calls)->toHaveCount($this->clock->sleeps);

    foreach ($repository->calls as $keys) {
        expect($keys)->toContain('asgard:push:app.native.screens.home#list', 'asgard:push:app.native.screens.counter#tick');
    }
});

it('allows exactly 16 subscriptions', function () {
    $subscriptions = array_map(fn ($i) => subscription(['id' => "s{$i}", 'regions' => ['greeting'], 'state' => ['name' => "N{$i}"]]), range(1, 16));

    expect(sseEvents($this->asgardStream(streamBody([], $subscriptions))->streamedContent()))->toHaveCount(16);

    $subscriptions[] = subscription(['id' => 's17']);

    $this->asgardStream(streamBody([], $subscriptions))->assertStatus(422)->assertJsonPath('error', 'invalid_request');
});

it('signals every subscribed region when the component is pushed, each with its new version', function () {
    $this->clock->onSleep[2] = fn () => Asgard::push('app.native.screens.home');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['event'], $e['region']], $events))->toBe([
        ['0', 'signal', 'greeting'], ['0', 'signal', 'list'],
        ['1', 'signal', 'greeting'], ['1', 'signal', 'list'],
    ]);
    expect($events[2]['fragment'])->toBeNull()
        ->and($events[2]['version'])->not->toBe($events[0]['version'])
        ->and($events[2]['version'])->toBe(renderedVersion($this, 'greeting'));
});

it('re-renders only the named region when a region is pushed', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home', 'list');
    $this->clock->onSleep[3] = fn () => Asgard::push(HomeScreen::class, 'list');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['region']], $events))->toBe([
        ['0', 'greeting'], ['0', 'list'],
        ['1', 'list'],
        ['2', 'list'],
    ]);
});

it('sends nothing for a region the stream did not subscribe to', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home', 'tags');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect($events)->toHaveCount(2);
});

it('sends nothing when another component is pushed', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.child');
    $this->clock->onSleep[2] = fn () => Asgard::push(ChildScreen::class, 'count');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect($events)->toHaveCount(2);
});

it('re-renders from a fresh hydration of the original state each time', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push(CounterScreen::class);
    $this->clock->onSleep[2] = fn () => Asgard::push(CounterScreen::class);

    $events = sseEvents($this->asgardStream(streamBody([
        'component' => 'app.native.screens.counter',
        'regions' => ['tick'],
        'state' => ['count' => 5],
    ]))->streamedContent());

    expect(array_column(array_column($events, 'fragment'), 'props'))->toBe([
        ['text' => 'Count: 6'], ['text' => 'Count: 6'], ['text' => 'Count: 6'],
    ]);
});

it('does not hydrate Locked props and ignores unknown ones, like render', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push(CounterScreen::class);

    $events = sseEvents($this->asgardStream(streamBody([
        'component' => 'app.native.screens.counter',
        'regions' => ['tick'],
        'state' => ['count' => 5, 'nope' => 1],
    ]))->streamedContent());

    expect($events)->toHaveCount(2);
    expect($events[1]['fragment'])->toBe(['type' => 'text', 'props' => ['text' => 'Count: 6']]);
});

it('renders a shared region once per state and version, for every stream', function () {
    $body = streamBody(['component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]]);
    CounterScreen::$renders = 0;

    $this->asgardStream($body)->streamedContent();
    $this->asgardStream($body)->streamedContent();
    $this->asgardStream(streamBody([], [subscription(['component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 5]])]))->streamedContent();

    expect(CounterScreen::$renders)->toBe(1);

    // Other state, or a push, is another render.
    $this->asgardStream(streamBody(['component' => 'app.native.screens.counter', 'regions' => ['tick'], 'state' => ['count' => 6]]))->streamedContent();
    Asgard::push(CounterScreen::class);
    $this->asgardStream($body)->streamedContent();

    expect(CounterScreen::$renders)->toBe(3);
});

it('sends a ping comment every 5 seconds', function () {
    config()->set('asgard.stream.lifetime', 12);

    $content = $this->asgardStream(streamBody(['regions' => ['greeting']]))->streamedContent();

    expect(substr_count($content, ": ping\n\n"))->toBe(2);
    expect(sseBlocks($content))->toHaveCount(4); // retry, fragment, ping, ping
    expect(sseBlocks($content)[2])->toBe(': ping');
});

it('ends the stream when the lifetime runs out', function () {
    config()->set('asgard.stream.lifetime', 3);
    config()->set('asgard.stream.poll_ms', 500);

    $this->asgardStream(streamBody())->streamedContent();

    expect($this->clock->sleeps)->toBe(6);
    expect($this->clock->time)->toBe(1003.0);
});

it('does not sleep past the lifetime', function () {
    config()->set('asgard.stream.lifetime', 1);
    config()->set('asgard.stream.poll_ms', 700);

    $this->asgardStream(streamBody())->streamedContent();

    expect($this->clock->sleeps)->toBe(2);
    expect($this->clock->time)->toBe(1001.0);
});

it('ends the stream when the client disconnects', function () {
    config()->set('asgard.stream.lifetime', 300);
    $this->clock->abortAfter = 4;
    $this->clock->onSleep[4] = fn () => Asgard::push('app.native.screens.home');

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect($this->clock->sleeps)->toBe(4);
    expect($events)->toHaveCount(2);
});

it('rejects bad stream requests before streaming', function (array $body, int $status, string $error) {
    $response = $this->asgardStream($body)->assertStatus($status)->assertJsonPath('error', $error);

    expect($response->baseResponse)->toBeInstanceOf(JsonResponse::class);
    expect($this->clock->sleeps)->toBe(0);
})->with([
    'wrong protocol' => [streamBody(['protocol' => 2]), 422, 'invalid_request'],
    'class as component' => [streamBody(['component' => 'Native\\Asgard\\Tests\\Fixtures\\HomeScreen']), 422, 'invalid_request'],
    'missing component' => [streamBody([], [array_diff_key(subscription(), ['component' => 1])]), 422, 'invalid_request'],
    'missing subscriptions' => [array_diff_key(streamBody(), ['subscriptions' => 1]), 422, 'invalid_request'],
    'no subscriptions' => [streamBody([], []), 422, 'invalid_request'],
    'subscriptions is an object' => [streamBody(['subscriptions' => ['a' => subscription()]]), 422, 'invalid_request'],
    'subscription is a list' => [streamBody([], [['s1', 'app.native.screens.home']]), 422, 'invalid_request'],
    'subscription is a string' => [streamBody([], ['s1']), 422, 'invalid_request'],
    'unknown subscription field' => [streamBody([], [subscription(['action' => ['call' => 'promote', 'args' => []]])]), 422, 'invalid_request'],
    'old single-component body' => [['protocol' => 1, 'component' => 'app.native.screens.home', 'regions' => ['greeting'], 'state' => [], 'platform' => 'ios'], 422, 'invalid_request'],
    'missing id' => [streamBody([], [array_diff_key(subscription(), ['id' => 1])]), 422, 'invalid_request'],
    'empty id' => [streamBody(['id' => '']), 422, 'invalid_request'],
    'id too long' => [streamBody(['id' => str_repeat('a', 33)]), 422, 'invalid_request'],
    'id with other characters' => [streamBody(['id' => 'a.b']), 422, 'invalid_request'],
    'id is a number' => [streamBody(['id' => 5]), 422, 'invalid_request'],
    'repeated id' => [streamBody([], [subscription(), subscription(['state' => []])]), 422, 'invalid_request'],
    'missing regions' => [streamBody([], [array_diff_key(subscription(), ['regions' => 1])]), 422, 'invalid_request'],
    'single region key only' => [streamBody([], [['id' => 's1', 'component' => 'app.native.screens.home', 'region' => 'greeting']]), 422, 'invalid_request'],
    'regions is a string' => [streamBody(['regions' => 'greeting']), 422, 'invalid_request'],
    'regions is an object' => [streamBody(['regions' => ['a' => 'greeting']]), 422, 'invalid_request'],
    'no regions' => [streamBody(['regions' => []]), 422, 'invalid_request'],
    'too many regions' => [streamBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 17))]), 422, 'invalid_request'],
    'duplicate regions' => [streamBody(['regions' => ['greeting', 'greeting']]), 422, 'invalid_request'],
    'region is not a string' => [streamBody(['regions' => ['greeting', 5]]), 422, 'invalid_request'],
    'bad region name' => [streamBody(['regions' => ['../x']]), 422, 'invalid_request'],
    'versions is a list' => [streamBody(['versions' => ['abc']]), 422, 'invalid_request'],
    'version for another region' => [streamBody(['versions' => ['tags' => 'abc']]), 422, 'invalid_request'],
    'version is not a string' => [streamBody(['versions' => ['greeting' => 5]]), 422, 'invalid_request'],
    'version is not a token' => [streamBody(['versions' => ['greeting' => 'a b']]), 422, 'invalid_request'],
    'bad platform' => [streamBody(['platform' => 'web']), 422, 'invalid_request'],
    'state is a list' => [streamBody(['state' => [1, 2]]), 422, 'invalid_request'],
    'state type mismatch' => [streamBody(['state' => ['count' => '5']]), 422, 'invalid_request'],
    'state mismatch in a later subscription' => [streamBody([], [subscription(), subscription(['id' => 's2', 'state' => ['count' => '5']])]), 422, 'invalid_request'],
    'action' => [streamBody(['action' => ['call' => 'promote', 'args' => []]]), 422, 'invalid_request'],
    'unknown component' => [streamBody(['component' => 'app.native.screens.nope']), 404, 'unknown_component'],
    'unknown component in a later subscription' => [streamBody([], [subscription(), subscription(['id' => 's2', 'component' => 'app.native.screens.nope'])]), 404, 'unknown_component'],
    'undeclared region' => [streamBody(['regions' => ['greeting', 'secret']]), 404, 'unknown_component'],
    'static region' => [streamBody(['regions' => ['static']]), 404, 'unknown_component'],
    'invalid shared fragment' => [streamBody(['component' => 'app.native.screens.bad', 'regions' => ['shared_garbage']]), 500, 'invalid_fragment'],
]);

it('allows exactly 16 regions', function () {
    // 16 distinct names that all fail lookup, so validation passes and the
    // registry check answers.
    $this->asgardStream(streamBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 16))]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);
});

it('rejects malformed JSON and POST without the method header', function () {
    $this->asgardStream('{nope')->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    $this->asgard(streamBody(), 'POST', [], 'stream')
        ->assertStatus(405)
        ->assertJsonPath('error', 'method_not_allowed');
});

it('gives identical 404s for an unknown component and an undeclared region', function () {
    $a = $this->asgardStream(streamBody(['component' => 'app.native.screens.nope']));
    $b = $this->asgardStream(streamBody(['regions' => ['greeting', 'nope']]));

    expect($a->getContent())->toBe($b->getContent());
});

it('does not run region methods for a request it rejects', function () {
    $this->asgardStream(streamBody(['regions' => ['greeting', 'secret']]))->assertNotFound();

    expect(HomeScreen::$calls)->toBe([]);
});

it('refuses to push an unknown component or region', function () {
    expect(fn () => Asgard::push('app.native.screens.nope'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Asgard::push(stdClass::class))->toThrow(InvalidArgumentException::class);
    expect(fn () => Asgard::push('app.native.screens.home', 'secret'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Asgard::push(HomeScreen::class, 'nope'))->toThrow(InvalidArgumentException::class);
});

it('pushes from the asgard:push command', function () {
    $this->clock->onSleep[1] = fn () => Artisan::call('asgard:push', ['component' => 'app.native.screens.home', 'region' => 'greeting']);
    $this->clock->onSleep[2] = fn () => Artisan::call('asgard:push', ['component' => HomeScreen::class]);

    $events = sseEvents($this->asgardStream(streamBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['region']], $events))->toBe([
        ['0', 'greeting'], ['0', 'list'],
        ['1', 'greeting'],
        ['2', 'greeting'], ['2', 'list'],
    ]);
});

it('reports a bad component or region from the command', function () {
    $this->artisan('asgard:push', ['component' => 'app.native.screens.home'])->assertSuccessful();
    $this->artisan('asgard:push', ['component' => 'app.native.screens.nope'])->assertFailed();
    $this->artisan('asgard:push', ['component' => 'app.native.screens.home', 'region' => 'secret'])->assertFailed();
});
