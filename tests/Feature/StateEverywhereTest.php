<?php

use Illuminate\Support\Facades\Log;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\StateScreen;

function stateRender(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.state',
        'regions' => ['quiet'],
        'state' => ['name' => 'Ada', 'count' => 1, 'platform' => 'ios'],
    ], $overrides);
}

function stateCall(string $method = 'bump', array $args = [], array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.state',
        'method' => $method,
        'args' => $args,
        'state' => ['name' => 'Ada', 'count' => 1, 'platform' => 'ios'],
    ], $overrides);
}

function stateUpload(array $overrides = []): string
{
    $bytes = 'hello';
    $header = json_encode(array_merge([
        'protocol' => 1, 'component' => 'app.native.screens.state', 'method' => 'attach', 'args' => [],
        'state' => ['name' => 'Ada', 'count' => 1, 'platform' => 'ios'],
        'filename' => 'a.txt', 'mime' => 'text/plain', 'size' => strlen($bytes),
    ], $overrides));

    return pack('N', strlen($header)).$header.$bytes;
}

/** A copy of a region made 5 seconds ago, fresh for 5 minutes. */
function freshCopy(): array
{
    return ['made_at' => now()->getTimestampMs() - 5000, 'ttl' => 300];
}

// A delta on every render

it('answers a plain render that changed nothing with an empty delta', function () {
    $response = $this->asgard(stateRender())->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'delta', 'fragments'])
        ->and($response->getContent())->toContain('"delta":{}');
});

it('sends back a prop the render itself changed', function () {
    $this->asgard(stateRender(['regions' => ['counted']]))
        ->assertOk()
        ->assertJsonPath('delta', ['views' => 1])
        ->assertJsonPath('fragments.counted.fragment.props.text', 'Views 1');
});

it('sends back a prop changed straight after hydration, before anything rendered', function () {
    // checkClientCache() is the hook that runs on the hydrated component
    // before any region renders. The region is still served from cache.
    $this->freezeTime();

    $this->asgard(stateRender(['cache' => ['quiet' => freshCopy()]]))
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['checked' => 'yes'], 'fragments' => ['quiet' => ['cached' => true]]]);

    expect(StateScreen::$calls)->toBe([]);
});

it('sends one delta for a batch', function () {
    $response = $this->asgard(stateRender(['regions' => ['quiet', 'counted', 'stats']]))->assertOk();

    expect($response->json('delta'))->toBe(['views' => 1])
        ->and(array_keys($response->json('fragments')))->toBe(['quiet', 'counted', 'stats']);
});

it('sends a computed value in a plain render only when the device holds a different one', function () {
    $this->asgard(stateRender(['state' => ['name' => 'Ada', 'count' => 1, 'label' => 'Ada (1)']]))
        ->assertOk()->assertJsonPath('delta', []);

    $this->asgard(stateRender(['state' => ['name' => 'Ada', 'count' => 1, 'label' => 'stale']]))
        ->assertOk()->assertJsonPath('delta', ['label' => 'Ada (1)']);
});

it('sends an asgard.state key in a plain render only when the device holds a different one', function () {
    config()->set('asgard.state', ['build' => '2', 'region' => 'eu']);

    $this->asgard(stateRender(['state' => ['count' => 1, 'build' => '1']]))
        ->assertOk()->assertJsonPath('delta', ['build' => '2']);
});

it('sends a server screen render a delta too, and a route string hydration converted is no change', function () {
    $this->asgard(['protocol' => 1, 'component' => 'promos.show', 'regions' => ['screen'], 'state' => ['id' => '7']])
        ->assertOk()
        ->assertJsonPath('delta', [])
        ->assertJsonPath('fragments.screen.fragment.children.0.props.text', 'Promo 7');
});

// Actions

it('sends back what an action changed, with computed values and asgard.state', function () {
    config()->set('asgard.state', ['build' => '2']);

    $response = $this->asgard(stateRender(['action' => ['call' => 'bump', 'args' => []]]))->assertOk();

    expect($response->json('delta'))->toBe(['count' => 2, 'label' => 'Ada (2)', 'build' => '2'])
        ->and($response->json('fragments.quiet.fragment.props.text'))->toBe('Count 2');
});

it('renders an action\'s regions before working out its delta, so they match', function () {
    $response = $this->asgard(stateRender(['regions' => ['counted'], 'action' => ['call' => 'bump', 'args' => ['stats']]]))->assertOk();

    expect($response->json('delta'))->toBe(['count' => 2, 'views' => 1, 'label' => 'Ada (2)'])
        ->and($response->json('fragments.counted.fragment.props.text'))->toBe('Views 1')
        ->and($response->json('fragments.stats.fragment.props.text'))->toBe('Stats 2');
});

it('never compresses an action answer that carries a delta', function () {
    $this->asgard(stateRender(['action' => ['call' => 'bump', 'args' => []]]))
        ->assertOk()->assertHeader('Asgard-Content-Coding', 'identity');
});

// read_only

it('checks read_only on every endpoint that takes state', function (mixed $readOnly, string $message) {
    $render = $this->asgard(stateRender(['read_only' => $readOnly]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    $call = $this->asgard(stateCall(overrides: ['read_only' => $readOnly]), 'QUERY', [], 'call')->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    $upload = $this->asgard(stateUpload(['read_only' => $readOnly]), 'QUERY', [], 'upload')->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    $stream = $this->asgardStream(streamBody(subscriptions: [
        ['id' => 's1', 'component' => 'app.native.screens.state', 'regions' => ['quiet'], 'state' => ['name' => 'Ada', 'count' => 1, 'platform' => 'ios'], 'read_only' => $readOnly],
    ]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    foreach ([$render, $call, $upload, $stream] as $response) {
        expect($response->json('message'))->toContain($message);
    }

    expect(StateScreen::$calls)->toBe([]);
})->with([
    'not a list' => [['a' => 'name'], 'must be a list of state keys'],
    'a string' => ['name', 'must be a list of state keys'],
    'not a state key' => [['missing'], 'must be a key of state'],
    'not a string' => [[1], 'must be a key of state'],
    'a repeat' => [['name', 'name'], 'must not repeat a key'],
    'more than state has' => [['name', 'count', 'platform', 'name'], 'names more keys than state has'],
]);

it('accepts read_only on a stream subscription', function () {
    $this->app->instance(StreamClock::class, new FakeClock);
    config()->set('asgard.stream.lifetime', 1);

    $this->asgardStream(streamBody(subscriptions: [
        ['id' => 's1', 'component' => 'app.native.screens.state', 'regions' => ['quiet'], 'state' => ['platform' => 'ios'], 'read_only' => ['platform']],
    ]))->assertOk();
});

it('fails a method that changes a read-only prop in debug, naming the prop and the method', function () {
    $this->asgard(stateCall('retarget', [], ['read_only' => ['platform']]), 'QUERY', [], 'call')
        ->assertStatus(500)
        ->assertJsonPath('error', 'read_only_changed')
        ->assertJsonPath('message', StateScreen::class.'::retarget() changed [platform], which the device sent as read-only.');
});

it('drops a read-only prop a method changed outside debug, and logs a warning', function () {
    config()->set('app.debug', false);
    Log::spy();

    $this->asgard(stateCall('retarget', [], ['read_only' => ['platform']]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['count' => 2, 'label' => 'Ada (2)']]);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn ($message, $context) => str_contains($message, StateScreen::class.'::retarget() changed [platform]') && $context['keys'] === ['platform'],
    );
});

it('enforces read_only on an action, an upload and a plain render', function () {
    $this->asgard(stateRender(['read_only' => ['platform'], 'action' => ['call' => 'retarget', 'args' => []]]))
        ->assertStatus(500)->assertJsonPath('error', 'read_only_changed');

    $this->asgard(stateRender(['regions' => ['meddles'], 'read_only' => ['platform']]))
        ->assertStatus(500)->assertJsonPath('error', 'read_only_changed')
        ->assertJsonPath('message', StateScreen::class.' rendering [meddles] changed [platform], which the device sent as read-only.');

    config()->set('app.debug', false);
    Log::spy();

    $this->asgard(stateRender(['regions' => ['meddles'], 'read_only' => ['platform']]))
        ->assertOk()->assertJsonPath('delta', []);

    // The upload changed only count, so read_only leaves it alone.
    $this->asgard(stateUpload(['read_only' => ['platform']]), 'QUERY', [], 'upload')
        ->assertOk()->assertJsonPath('delta', ['count' => 11, 'label' => 'Ada (11)']);

    Log::shouldHaveReceived('warning')->once();
});

it('lets a method change a prop that is not read-only as before', function () {
    $this->asgard(stateCall('retarget', [], ['read_only' => ['name']]), 'QUERY', [], 'call')
        ->assertOk()->assertJsonPath('delta', ['count' => 2, 'platform' => 'android', 'label' => 'Ada (2)']);
});

it('never overwrites a read-only key with an asgard.state key of the same name', function () {
    config()->set('asgard.state', ['locale' => 'fr', 'build' => '2']);

    $state = ['count' => 1, 'locale' => 'en'];

    $this->asgard(stateCall(overrides: ['state' => $state, 'read_only' => ['locale']]), 'QUERY', [], 'call')
        ->assertOk()->assertExactJson(['protocol' => 1, 'delta' => ['count' => 2, 'label' => ' (2)', 'build' => '2']]);

    $this->asgard(stateRender(['state' => $state + ['build' => '1'], 'read_only' => ['locale']]))
        ->assertOk()->assertJsonPath('delta', ['build' => '2']);
});

it('leaves out a read-only computed value the device already holds', function () {
    $this->asgard(stateCall('nothing', [], ['state' => ['name' => 'Ada', 'count' => 1, 'label' => 'Ada (1)'], 'read_only' => ['label']]), 'QUERY', [], 'call')
        ->assertOk()->assertExactJson(['protocol' => 1, 'delta' => []]);

    $this->asgard(stateCall('bump', [], ['state' => ['name' => 'Ada', 'count' => 1, 'label' => 'Ada (1)'], 'read_only' => ['label']]), 'QUERY', [], 'call')
        ->assertStatus(500)->assertJsonPath('error', 'read_only_changed');
});

// regions on calls and uploads

it('renders the regions a call names after the method runs', function () {
    $response = $this->asgard(stateCall(overrides: ['regions' => ['stats', 'list']]), 'QUERY', [], 'call')->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'delta', 'fragments'])
        ->and($response->json('delta'))->toBe(['count' => 2, 'label' => 'Ada (2)'])
        ->and(array_keys($response->json('fragments')))->toBe(['stats', 'list'])
        ->and($response->json('fragments.stats.fragment.props.text'))->toBe('Stats 2')
        ->and($response->json('fragments.stats.version'))->toMatch('/^[A-Za-z0-9_-]{16}$/')
        ->and(StateScreen::$calls)->toBe(['bump', 'stats', 'list']);
});

it('renders the union of a call\'s regions and what refresh() asked for, each once', function () {
    $response = $this->asgard(stateCall('bump', ['list', 'counted'], ['regions' => ['stats', 'list']]), 'QUERY', [], 'call')->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['stats', 'list', 'counted'])
        ->and($response->json('delta'))->toBe(['count' => 2, 'views' => 1, 'label' => 'Ada (2)'])
        ->and(StateScreen::$calls)->toBe(['bump', 'stats', 'list', 'counted']);
});

it('renders the regions an upload names', function () {
    $this->asgard(stateUpload(['regions' => ['stats']]), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertJsonPath('delta', ['count' => 11, 'label' => 'Ada (11)'])
        ->assertJsonPath('fragments.stats.fragment.props.text', 'Stats 11');
});

it('refuses a call or upload that names an unknown region with 404, before the method runs', function () {
    $this->asgard(stateCall(overrides: ['regions' => ['stats', 'nope']]), 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);

    $this->asgard(stateUpload(['regions' => ['nope']]), 'QUERY', [], 'upload')
        ->assertNotFound()->assertExactJson(['error' => 'unknown_component']);

    expect(StateScreen::$calls)->toBe([]);
});

it('refuses a badly shaped regions on a call', function (mixed $regions) {
    $this->asgard(stateCall(overrides: ['regions' => $regions]), 'QUERY', [], 'call')
        ->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    expect(StateScreen::$calls)->toBe([]);
})->with([
    'a string' => ['stats'],
    'an object' => [['a' => 'stats']],
    'a bad name' => [['no spaces']],
    'a repeat' => [['stats', 'stats']],
]);

it('treats an empty regions on a call as none', function () {
    $this->asgard(stateCall(overrides: ['regions' => []]), 'QUERY', [], 'call')
        ->assertOk()->assertExactJson(['protocol' => 1, 'delta' => ['count' => 2, 'label' => 'Ada (2)']]);
});

it('holds a call\'s regions to asgard.max_regions', function () {
    config()->set('asgard.max_regions', 2);

    $this->asgard(stateCall(overrides: ['regions' => ['stats', 'list', 'quiet']]), 'QUERY', [], 'call')
        ->assertStatus(422)->assertJsonPath('error', 'too_many_regions');

    $this->asgard(stateUpload(['regions' => ['stats', 'list', 'quiet']]), 'QUERY', [], 'upload')
        ->assertStatus(422)->assertJsonPath('error', 'too_many_regions');

    $this->asgard(stateCall(overrides: ['regions' => ['stats', 'list']]), 'QUERY', [], 'call')->assertOk();

    expect(StateScreen::$calls)->toBe(['bump', 'stats', 'list']);
});

it('serves a call\'s regions from the client cache under the usual rules', function () {
    $this->freezeTime();

    $response = $this->asgard(stateCall(overrides: ['regions' => ['stats', 'list'], 'cache' => ['stats' => freshCopy()]]), 'QUERY', [], 'call')->assertOk();

    expect($response->json('fragments.stats'))->toBe(['cached' => true])
        ->and($response->json('fragments.list.fragment.props.text'))->toBe('List 2')
        ->and(StateScreen::$calls)->toBe(['bump', 'list']);
});

it('never compresses a call answer, even with large fragments', function () {
    $this->asgard([
        'protocol' => 1, 'component' => 'app.native.screens.rows', 'method' => 'more', 'args' => [],
        'state' => ['count' => 200], 'regions' => ['rows'],
    ], 'QUERY', [], 'call')
        ->assertOk()
        ->assertJsonCount(210, 'fragments.rows.fragment')
        ->assertHeader('Asgard-Content-Coding', 'identity');
});

it('answers invalid_delta, not a crash, when a render leaves a value that cannot be serialized in a public prop', function () {
    config(['app.debug' => true]);

    $this->asgard(stateRender(['regions' => ['lazy']]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_delta');
});
