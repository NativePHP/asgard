<?php

use Native\Asgard\Support\PushVersions;
use Native\Asgard\Tests\Fixtures\ChannelScreen;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\Fixtures\ServerMethodScreen;
use Native\Asgard\Tests\Fixtures\User;

function serverCall(string $method, array $args = [], array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.server_methods',
        'method' => $method,
        'args' => $args,
        'state' => ['range' => '7d'],
        'platform' => 'ios',
    ], $overrides);
}

function refreshAction(array $action, array $overrides = []): array
{
    return asgardBody(array_merge(['state' => ['name' => 'Simon', 'count' => 2], 'action' => $action], $overrides));
}

// ── Same component, inline ──────────────────────────

it('renders the regions an action asked for in the same answer', function () {
    $response = $this->asgard(refreshAction(['call' => 'redraw', 'args' => ['list', 'tags']]))->assertOk();

    // The acting region is still the answer's own fragment.
    expect($response->json('region'))->toBe('greeting')
        ->and($response->json('fragment.children.2.props.text'))->toBe('Count: 3')
        ->and(array_keys($response->json('fragments')))->toBe(['list', 'tags'])
        ->and($response->json('fragments.list.version'))->toMatch('/^[A-Za-z0-9_-]{16}$/')
        ->and($response->json('fragments.tags.fragment'))->toBe([
            'type' => 'row',
            'children' => [
                ['type' => 'text', 'props' => ['text' => 'a']],
                ['type' => 'text', 'props' => ['text' => 'b']],
            ],
        ])
        ->and($response->json())->not->toHaveKey('refresh');

    // Each region's token is the one a render of it would carry.
    $render = $this->asgard(asgardBody(['region' => 'tags', 'state' => ['name' => 'Simon', 'count' => 2]]));
    expect($response->json('fragments.tags.version'))->toBe($render->json('version'));
});

it('leaves out the acting region when an action names it too', function () {
    $response = $this->asgard(refreshAction(['call' => 'redraw', 'args' => ['greeting', 'list']]))->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['list']);
});

it('sends no fragments member when an action asks for nothing', function () {
    $response = $this->asgard(refreshAction(['call' => 'shuffle', 'args' => []]))->assertOk();

    expect($response->json())->not->toHaveKey('fragments')->not->toHaveKey('refresh');
});

it('renders the regions a #[Server] call asked for after the delta', function () {
    $response = $this->asgard(serverCall('rollAndRedraw', ['rolled', 'chart']), 'QUERY', [], 'call')->assertOk();

    expect($response->json('delta'))->toBe(['roll' => 6])
        ->and(array_keys($response->json('fragments')))->toBe(['rolled', 'chart'])
        ->and($response->json('fragments.rolled.fragment.props.text'))->toBe('Rolled 6 on ios in rolled')
        ->and($response->json('fragments.rolled.version'))->toMatch('/^[A-Za-z0-9_-]{16}$/')
        ->and($response->json('fragments.chart.fragment.props.text'))->toBe('chart')
        ->and($response->json())->not->toHaveKey('refresh');
});

it('keeps a call answer that asked for nothing to its delta', function () {
    $response = $this->asgard(serverCall('rollDie'), 'QUERY', [], 'call')->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"delta":{"roll":4}}');
});

it('renders inline regions as the request user, not as a guest', function () {
    $this->actingAs((new User)->forceFill(['id' => 5]));

    $action = $this->asgard(asgardBody([
        'component' => 'app.native.screens.channel', 'region' => 'body', 'state' => ['promo' => 42],
        'action' => ['call' => 'poke', 'args' => []],
    ]))->assertOk();

    expect($action->json('fragments.title.fragment.props.text'))->toBe('Promo 42 for 5');

    // A shared region too: only the stream renders those as a guest.
    $call = $this->asgard(serverCall('touch', [], ['component' => 'app.native.screens.channel', 'state' => ['promo' => 42]]), 'QUERY', [], 'call')->assertOk();

    expect($call->json('fragments.title.fragment.props.text'))->toBe('Promo 42 for 5')
        ->and($call->json('fragments.viewer.fragment.props.text'))->toBe('Viewer 5 / 5 of promo 42');
});

it('refuses an unknown region of its own', function (string $endpoint, array $body) {
    $this->asgard($body, 'QUERY', [], $endpoint)
        ->assertStatus(500)
        ->assertJsonPath('message', '[nope] is not a #[Region] on ['.($endpoint === 'call' ? ServerMethodScreen::class : HomeScreen::class).'].');
})->with([
    'action' => ['render', fn () => refreshAction(['call' => 'redraw', 'args' => ['list', 'nope']])],
    'call' => ['call', fn () => serverCall('rollAndRedraw', ['chart', 'nope'])],
]);

// ── Other components, a hint ─────────────────────────

it('names another component by class or address as a hint', function (array $args, array $refresh) {
    $response = $this->asgard(refreshAction(['call' => 'hint', 'args' => $args]))->assertOk();

    expect($response->json('refresh'))->toBe($refresh)
        ->and($response->json())->not->toHaveKey('fragments');
})->with([
    'a class and region' => [[ChildScreen::class, 'count'], [['component' => 'app.native.screens.child', 'region' => 'count']]],
    'an address' => [['app.native.screens.child'], [['component' => 'app.native.screens.child']]],
    'a class under two addresses' => [[ChannelScreen::class, 'title'], [
        ['component' => 'app.native.screens.channel', 'region' => 'title'],
        ['component' => 'app.native.screens.channel_two', 'region' => 'title'],
    ]],
]);

it('merges hints, with a whole component covering its regions', function () {
    $response = $this->asgard(refreshAction(['call' => 'hintMany', 'args' => []]))->assertOk();

    expect($response->json('refresh'))->toBe([
        ['component' => 'app.native.screens.child'],
        ['component' => 'app.native.screens.channel', 'region' => 'title'],
    ])->and(array_keys($response->json('fragments')))->toBe(['list', 'tags']);
});

it('carries hints on a call answer', function () {
    $response = $this->asgard(serverCall('hintOther', ['app.native.screens.home', 'list']), 'QUERY', [], 'call')->assertOk();

    expect($response->json('refresh'))->toBe([['component' => 'app.native.screens.home', 'region' => 'list']])
        ->and($response->json())->not->toHaveKey('fragments');
});

it('refuses a hint for an unknown component or region', function (array $args, string $message) {
    $this->asgard(refreshAction(['call' => 'hint', 'args' => $args]))
        ->assertStatus(500)
        ->assertJsonPath('message', $message);
})->with([
    'unknown address' => [['app.native.screens.nope'], '[app.native.screens.nope] is not a registered Asgard component address or class.'],
    'unknown region' => [['app.native.screens.child', 'nope'], '[nope] is not a #[Region] on ['.ChildScreen::class.'].'],
    'two regions' => [['app.native.screens.child', 'count', 'count'], 'A refresh of another component names at most one region.'],
]);

it('hides why a refresh was refused outside debug', function () {
    config()->set('app.debug', false);

    $this->asgard(refreshAction(['call' => 'hint', 'args' => ['app.native.screens.nope']]))
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);
});

it('does not push anything for a hint', function () {
    $before = app(PushVersions::class)->snapshot('app.native.screens.child', ['count']);

    $this->asgard(refreshAction(['call' => 'hint', 'args' => ['app.native.screens.child']]))->assertOk();

    expect(app(PushVersions::class)->snapshot('app.native.screens.child', ['count']))->toBe($before);
});

// ── Batch renders ────────────────────────────────────

it('renders several regions in one answer, keyed by region', function () {
    $body = asgardBody(['regions' => ['greeting', 'tags'], 'state' => ['name' => 'Simon', 'tags' => ['x']]]);
    unset($body['region']);

    $response = $this->asgard($body)->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'fragments'])
        ->and(array_keys($response->json('fragments')))->toBe(['greeting', 'tags'])
        ->and($response->json('fragments.greeting.fragment.children.0.props.text'))->toBe('Hello, Simon')
        ->and($response->json('fragments.tags.fragment.children.0.props.text'))->toBe('x');

    foreach (['greeting', 'tags'] as $region) {
        $single = $this->asgard(asgardBody(['region' => $region, 'state' => ['name' => 'Simon', 'tags' => ['x']]]));

        expect($response->json("fragments.{$region}.version"))->toBe($single->json('version'))
            ->and($response->json("fragments.{$region}.fragment"))->toBe($single->json('fragment'));
    }
});

it('sends a batch of one as a batch answer', function () {
    $body = asgardBody(['regions' => ['list']]);
    unset($body['region']);

    $response = $this->asgard($body)->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['list']);
});

it('carries a server screen\'s chrome in its batch part', function () {
    $body = ['protocol' => 1, 'component' => 'promos.show', 'regions' => ['screen'], 'state' => ['id' => '7'], 'platform' => 'ios'];

    $batch = $this->asgard($body)->assertOk();
    $single = $this->asgard([...array_diff_key($body, ['regions' => 1]), 'region' => 'screen'])->assertOk();

    expect($batch->json('fragments.screen.chrome'))->toBe($single->json('chrome'))
        ->and($batch->json('fragments.screen.fragment'))->toBe($single->json('fragment'));
});

it('refuses a malformed batch', function (array $overrides, string $message) {
    $body = array_merge(asgardBody(), $overrides);
    unset($body['region']);
    $body = array_merge($body, array_intersect_key($overrides, ['region' => 1]));

    $this->asgard($body, 'QUERY', [], 'render')
        ->assertStatus(422)
        ->assertJsonPath('message', $message);
})->with([
    'empty' => [['regions' => []], 'regions must be a list of 1 to 16 region names.'],
    'not a list' => [['regions' => ['a' => 'greeting']], 'regions must be a list of 1 to 16 region names.'],
    'too many' => [['regions' => array_map(fn ($i) => "r{$i}", range(1, 17))], 'regions must be a list of 1 to 16 region names.'],
    'a bad name' => [['regions' => ['greeting', 'no spaces']], 'regions[1] must be a region name.'],
    'a repeat' => [['regions' => ['greeting', 'greeting']], 'regions must not repeat a name.'],
    'with region' => [['regions' => ['greeting'], 'region' => 'greeting'], 'Send region or regions, not both.'],
    'with an action' => [['regions' => ['greeting'], 'action' => ['call' => 'shuffle', 'args' => []]], 'An action names one region, not regions.'],
]);

it('refuses a batch with any unknown region with the usual 404', function () {
    $body = asgardBody(['regions' => ['greeting', 'nope']]);
    unset($body['region']);

    $this->asgard($body)->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
});

it('checks sixteen names before it looks them up', function () {
    $body = asgardBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 16))]);
    unset($body['region']);

    $this->asgard($body)->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
});
