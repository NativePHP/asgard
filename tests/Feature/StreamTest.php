<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\CounterScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\HomeScreen;
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

it('sends retry and the current fragment of each region in SSE format', function () {
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
        ->and($content)->toMatch('/^retry: 3000\n\n(id: 0\nevent: fragment\ndata: \{"body":"[A-Za-z0-9_-]+","enc":"identity","sig":"[A-Za-z0-9_-]{43}"\}\n\n){2}$/D')
        ->and($content)->not->toContain('Hello, Simon');

    expect(sseBlocks($content)[1]['data'])->toBe(['protocol' => 1, 'region' => 'greeting', 'fragment' => GREETING])
        ->and(sseBlocks($content)[2]['data'])->toBe([
            'protocol' => 1,
            'region' => 'list',
            'fragment' => [['type' => 'text', 'props' => ['text' => 'one']], ['type' => 'text', 'props' => ['text' => 'two']]],
        ]);
});

it('accepts a real QUERY request', function () {
    $response = $this->asgardStream(streamBody(['regions' => ['greeting']]), 'QUERY')->assertOk();

    expect(sseFragments($response->streamedContent()))->toHaveCount(1);
});

it('still sends the current fragments on reconnect with Last-Event-ID', function () {
    $response = $this->asgardStream(streamBody(), 'POST', ['Last-Event-ID' => '7'])->assertOk();

    expect(array_column(sseFragments($response->streamedContent()), 'region'))->toBe(['greeting', 'list']);
});

it('re-renders every subscribed region when the component is pushed', function () {
    $this->clock->onSleep[2] = fn () => Asgard::push('app.native.screens.home');

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['region']], $events))->toBe([
        ['0', 'greeting'], ['0', 'list'],
        ['1', 'greeting'], ['1', 'list'],
    ]);
    expect($events[2]['fragment'])->toBe(GREETING);
});

it('re-renders only the named region when a region is pushed', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home', 'list');
    $this->clock->onSleep[3] = fn () => Asgard::push(HomeScreen::class, 'list');

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['region']], $events))->toBe([
        ['0', 'greeting'], ['0', 'list'],
        ['1', 'list'],
        ['2', 'list'],
    ]);
});

it('sends nothing for a region the stream did not subscribe to', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home', 'tags');

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

    expect($events)->toHaveCount(2);
});

it('sends nothing when another component is pushed', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.child');
    $this->clock->onSleep[2] = fn () => Asgard::push(ChildScreen::class, 'count');

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

    expect($events)->toHaveCount(2);
});

it('re-renders from a fresh hydration of the original state each time', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push(CounterScreen::class);
    $this->clock->onSleep[2] = fn () => Asgard::push(CounterScreen::class);

    $events = sseFragments($this->asgardStream(streamBody([
        'component' => 'app.native.screens.counter',
        'regions' => ['tick'],
        'state' => ['count' => 5],
    ]))->streamedContent());

    expect(array_column(array_column($events, 'fragment'), 'props'))->toBe([
        ['text' => 'Count: 6'], ['text' => 'Count: 6'], ['text' => 'Count: 6'],
    ]);
});

it('does not hydrate Locked props and ignores unknown ones, like render', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.home');

    $events = sseFragments($this->asgardStream(streamBody([
        'regions' => ['greeting'],
        'state' => ['name' => 'Simon', 'role' => 'admin', 'nope' => 1, 'untyped' => 'x'],
    ]))->streamedContent());

    expect($events)->toHaveCount(2);
    expect($events[0]['fragment'])->toBe(GREETING);
    expect($events[1]['fragment'])->toBe(GREETING);
});

it('sends a ping comment every 15 seconds', function () {
    config()->set('asgard.stream.lifetime', 40);

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

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

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
    'missing regions' => [array_diff_key(streamBody(), ['regions' => 1]), 422, 'invalid_request'],
    'single region key only' => [array_diff_key(streamBody(['region' => 'greeting']), ['regions' => 1]), 422, 'invalid_request'],
    'regions is a string' => [streamBody(['regions' => 'greeting']), 422, 'invalid_request'],
    'regions is an object' => [streamBody(['regions' => ['a' => 'greeting']]), 422, 'invalid_request'],
    'no regions' => [streamBody(['regions' => []]), 422, 'invalid_request'],
    'too many regions' => [streamBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 17))]), 422, 'invalid_request'],
    'duplicate regions' => [streamBody(['regions' => ['greeting', 'greeting']]), 422, 'invalid_request'],
    'region is not a string' => [streamBody(['regions' => ['greeting', 5]]), 422, 'invalid_request'],
    'bad region name' => [streamBody(['regions' => ['../x']]), 422, 'invalid_request'],
    'bad platform' => [streamBody(['platform' => 'web']), 422, 'invalid_request'],
    'state is a list' => [streamBody(['state' => [1, 2]]), 422, 'invalid_request'],
    'state type mismatch' => [streamBody(['state' => ['count' => '5']]), 422, 'invalid_request'],
    'action' => [streamBody(['action' => ['call' => 'promote', 'args' => []]]), 422, 'invalid_request'],
    'unknown component' => [streamBody(['component' => 'app.native.screens.nope']), 404, 'unknown_component'],
    'undeclared region' => [streamBody(['regions' => ['greeting', 'secret']]), 404, 'unknown_component'],
    'static region' => [streamBody(['regions' => ['static']]), 404, 'unknown_component'],
    'invalid fragment' => [streamBody(['component' => 'app.native.screens.bad', 'regions' => ['garbage']]), 500, 'invalid_fragment'],
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

    $events = sseFragments($this->asgardStream(streamBody())->streamedContent());

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
