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
        'state' => ['range' => '7d', 'platform' => 'ios'],
    ], $overrides);
}

function refreshAction(array $action, array $overrides = []): array
{
    return asgardBody(array_merge(['state' => ['name' => 'Simon', 'count' => 2], 'action' => $action], $overrides));
}

// ── Same component, inline ──────────────────────────

it('renders the regions an action asked for in the same answer', function () {
    $response = $this->asgard(refreshAction(['call' => 'redraw', 'args' => ['list', 'tags']]))->assertOk();

    // The action's own region comes first, then the ones it asked for.
    expect(array_keys($response->json()))->toBe(['protocol', 'delta', 'fragments'])
        ->and($response->json('fragments.greeting.fragment.children.2.props.text'))->toBe('Count: 3')
        ->and(array_keys($response->json('fragments')))->toBe(['greeting', 'list', 'tags'])
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
    $render = $this->asgard(asgardBody(['regions' => ['tags'], 'state' => ['name' => 'Simon', 'count' => 2]]));
    expect($response->json('fragments.tags.version'))->toBe($render->json('fragments.tags.version'));
});

it('renders the action\'s own region once when the action names it too', function () {
    $response = $this->asgard(refreshAction(['call' => 'redraw', 'args' => ['list', 'greeting']]))->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['greeting', 'list']);
});

it('answers only the action\'s own region when an action asks for nothing', function () {
    $response = $this->asgard(refreshAction(['call' => 'shuffle', 'args' => []]))->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'delta', 'fragments'])
        ->and(array_keys($response->json('fragments')))->toBe(['greeting']);
});

it('renders the regions a #[Server] call asked for in the same answer', function () {
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
        'component' => 'app.native.screens.channel', 'regions' => ['body'], 'state' => ['promo' => 42],
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
        ->and(array_keys($response->json('fragments')))->toBe(['greeting']);
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
    ])->and(array_keys($response->json('fragments')))->toBe(['greeting', 'list', 'tags']);
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

// ── Several regions in one request ──────────────────

it('renders several regions in one answer, keyed by region', function () {
    $body = asgardBody(['regions' => ['greeting', 'tags'], 'state' => ['name' => 'Simon', 'tags' => ['x']]]);

    $response = $this->asgard($body)->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'delta', 'fragments'])
        ->and(array_keys($response->json('fragments')))->toBe(['greeting', 'tags'])
        ->and($response->json('fragments.greeting.fragment.children.0.props.text'))->toBe('Hello, Simon')
        ->and($response->json('fragments.tags.fragment.children.0.props.text'))->toBe('x');

    foreach (['greeting', 'tags'] as $region) {
        $single = $this->asgard(asgardBody(['regions' => [$region], 'state' => ['name' => 'Simon', 'tags' => ['x']]]));

        expect($response->json("fragments.{$region}.version"))->toBe($single->json("fragments.{$region}.version"))
            ->and($response->json("fragments.{$region}.fragment"))->toBe($single->json("fragments.{$region}.fragment"));
    }
});

it('carries a server screen\'s chrome in its part', function () {
    $response = $this->asgard(['protocol' => 1, 'component' => 'promos.show', 'regions' => ['screen'], 'state' => ['id' => '7']])->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['screen'])
        ->and(array_keys($response->json('fragments.screen')))->toBe(['version', 'made_at', 'fragment', 'chrome']);
});

it('refuses a malformed regions list', function (array $body, string $message) {
    $this->asgard($body, 'QUERY', [], 'render')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request')
        ->assertJsonPath('message', $message);
})->with([
    'missing' => [fn () => array_diff_key(asgardBody(), ['regions' => 1]), 'regions must be a list of at least one region name.'],
    'empty' => [fn () => asgardBody(['regions' => []]), 'regions must be a list of at least one region name.'],
    'not a list' => [fn () => asgardBody(['regions' => ['a' => 'greeting']]), 'regions must be a list of at least one region name.'],
    'a bad name' => [fn () => asgardBody(['regions' => ['greeting', 'no spaces']]), 'regions[1] must be a region name.'],
    'a repeat' => [fn () => asgardBody(['regions' => ['greeting', 'greeting']]), 'regions must not repeat a name.'],
    'the old region field' => [fn () => [...array_diff_key(asgardBody(), ['regions' => 1]), 'region' => 'greeting'], 'region is not a field. Send regions, a list even for one region.'],
    'region beside regions' => [fn () => asgardBody(['region' => 'greeting']), 'region is not a field. Send regions, a list even for one region.'],
    'an action with two regions' => [fn () => asgardBody(['regions' => ['greeting', 'list'], 'action' => ['call' => 'shuffle', 'args' => []]]), 'A request with an action names exactly one region in regions.'],
]);

it('refuses a request with any unknown region with the usual 404', function () {
    $this->asgard(asgardBody(['regions' => ['greeting', 'nope']]))->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
});

it('checks sixteen names before it looks them up', function () {
    $this->asgard(asgardBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 16))]))
        ->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
});

it('answers a request over the limit with too_many_regions and renders nothing', function () {
    $this->asgard(asgardBody(['regions' => array_map(fn ($i) => "r{$i}", range(1, 17))]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'too_many_regions');

    // Over the limit even when every name is real.
    config()->set('asgard.max_regions', 2);

    $this->asgard(asgardBody(['regions' => ['greeting', 'list', 'tags']]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'too_many_regions')
        ->assertJsonMissingPath('fragments');

    $this->asgard(asgardBody(['regions' => ['greeting', 'list']]))->assertOk();
});
