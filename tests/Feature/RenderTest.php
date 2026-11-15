<?php

use Illuminate\Support\Facades\Log;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Node;
use Native\Asgard\Tests\Fixtures\BadFragments;
use Native\Asgard\Tests\Fixtures\HeaderScreen;
use Native\Asgard\Tests\Fixtures\HomeScreen;

it('renders a region over QUERY', function () {
    $response = $this->asgard(asgardBody())->assertOk();

    expect($response->json('version'))->toMatch('/^[A-Za-z0-9_-]{16}$/');
    $response->assertExactJson([
        'protocol' => 1,
        'version' => $response->json('version'),
        'made_at' => $response->json('made_at'),
        'region' => 'greeting',
        'fragment' => [
            'type' => 'column',
            'class' => 'p-4 gap-2',
            'children' => [
                ['type' => 'text', 'key' => 'hello', 'props' => ['text' => 'Hello, Simon']],
                ['type' => 'text', 'props' => ['text' => 'Role: guest']],
                ['type' => 'text', 'props' => ['text' => 'Count: 0']],
                ['type' => 'text', 'props' => ['text' => 'Platform: ios']],
            ],
        ],
    ]);
});

it('renders a region over POST with the method header', function () {
    $this->asgard(asgardBody(['state' => ['name' => 'Simon', 'platform' => 'android']]), 'POST', ['X-Asgard-Method' => 'QUERY'])
        ->assertOk()
        ->assertJsonPath('fragment.children.0.props.text', 'Hello, Simon')
        ->assertJsonPath('fragment.children.3.props.text', 'Platform: android');
});

it('ignores a top-level platform from an older client', function () {
    $this->asgard(asgardBody(['platform' => 'web']))
        ->assertOk()
        ->assertJsonPath('fragment.children.3.props.text', 'Platform: ios');
});

it('ignores state keys no property takes', function () {
    $this->asgard(asgardBody(['state' => ['name' => 'Ada', 'app_version' => '2.1.0', 'device' => ['model' => 'x']]]))
        ->assertOk()
        ->assertJsonPath('fragment.children.0.props.text', 'Hello, Ada')
        ->assertJsonPath('fragment.children.3.props.text', 'Platform: ');
});

it('verifies a request that carries extra headers, and lets the app read them', function () {
    Asgard::component('app.native.screens.headers', HeaderScreen::class);

    $this->asgard(asgardBody(['component' => 'app.native.screens.headers', 'region' => 'version']), 'QUERY', [
        'X-App-Version' => '2.1.0',
        'Accept-Language' => 'cy',
    ])
        ->assertOk()
        ->assertJsonPath('fragment.props.text', '2.1.0 cy');
});

it('sets no cookies', function () {
    $response = $this->asgard(asgardBody());

    expect($response->headers->getCookies())->toBe([]);
});

it('rejects POST without the method header', function () {
    $this->asgard(asgardBody(), 'POST')
        ->assertStatus(405)
        ->assertJsonPath('error', 'method_not_allowed');
});

it('returns a list fragment when the region returns a list', function () {
    $this->asgard(asgardBody(['region' => 'list']))
        ->assertOk()
        ->assertJsonPath('fragment', [
            ['type' => 'text', 'props' => ['text' => 'one']],
            ['type' => 'text', 'props' => ['text' => 'two']],
        ]);
});

it('gives identical 404s for an unknown component and an undeclared region', function () {
    $unknownComponent = $this->asgard(asgardBody(['component' => 'app.native.screens.nope']));
    $unknownRegion = $this->asgard(asgardBody(['region' => 'nope']));

    $unknownComponent->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
    $unknownRegion->assertNotFound()->assertExactJson(['error' => 'unknown_component']);
    expect($unknownComponent->getContent())->toBe($unknownRegion->getContent());
});

it('never treats the component string as a class name', function () {
    $this->asgard(asgardBody(['component' => 'native.asgard.tests.fixtures.home_screen']))
        ->assertNotFound();

    $this->asgard(asgardBody(['component' => 'Native\\Asgard\\Tests\\Fixtures\\HomeScreen']))
        ->assertStatus(422);
});

it('does not reach public methods without a Region attribute', function (string $region) {
    $this->asgard(asgardBody(['region' => $region]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);

    expect(HomeScreen::$calls)->toBe([]);
})->with(['secret', 'greeting2', 'setAsgardContext', 'static', '__construct']);

it('does not hydrate Locked props', function () {
    $this->asgard(asgardBody(['state' => ['role' => 'admin']]))
        ->assertOk()
        ->assertJsonPath('fragment.children.1.props.text', 'Role: guest');
});

it('rejects state that does not fit the property type', function (array $state) {
    $this->asgard(asgardBody(['state' => $state]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');
})->with([
    'int into string' => [['name' => 42]],
    'string into int' => [['count' => '5']],
    'array into string' => [['name' => ['a']]],
    'null into non-null' => [['name' => null]],
    'string into array' => [['tags' => 'a']],
]);

it('accepts scalars, null and nested arrays that fit', function () {
    $this->asgard(asgardBody(['region' => 'tags', 'state' => ['tags' => ['a', 1, true], 'ratio' => 2, 'count' => 3]]))
        ->assertOk()
        ->assertJsonCount(3, 'fragment.children');
});

it('ignores unknown state keys and logs them in debug', function () {
    Log::spy();

    $this->asgard(asgardBody(['state' => ['name' => 'Simon', 'nope' => 1, 'untyped' => 'x', 'asgardRegion' => 'x', '__proto__' => 1]]))
        ->assertOk()
        ->assertJsonPath('fragment.children.0.props.text', 'Hello, Simon');

    Log::shouldHaveReceived('debug')->once()->withArgs(fn ($message, $context) => $context['keys'] === ['nope', 'untyped', 'asgardRegion', '__proto__']);
});

it('rejects malformed requests with 422', function (string $body) {
    $this->asgard($body)
        ->assertStatus(422)
        ->assertJsonStructure(['error', 'message'])
        ->assertJsonPath('error', 'invalid_request');
})->with([
    'not json' => ['{nope'],
    'list body' => ['[1,2]'],
    'wrong protocol' => [json_encode(asgardBody(['protocol' => 2]))],
    'missing component' => [json_encode(asgardBody(['component' => null]))],
    'state is a list' => [json_encode(asgardBody(['state' => [1, 2]]))],
    'bad region' => [json_encode(asgardBody(['region' => '../x']))],
    'address segment starting with a digit' => [json_encode(asgardBody(['component' => 'app.2native.home']))],
    'address segment starting with an underscore' => [json_encode(asgardBody(['component' => '_app.home']))],
]);

it('rejects denied fragment types', function (string $type) {
    Asgard::component('app.native.screens.bad', BadFragments::class);

    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'region' => 'denied', 'state' => ['type' => $type]]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment')
        ->assertJsonPath('message', fn ($m) => str_contains($m, "[{$type}] is not allowed"));
})->with(['top_bar', 'bottom_nav', 'fab', 'server_region', 'side_nav_item']);

it('hides the fragment error message outside debug', function () {
    config()->set('app.debug', false);

    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'region' => 'garbage']))
        ->assertStatus(500)
        ->assertExactJson(['error' => 'invalid_fragment']);
});

it('strips internal prop keys', function () {
    $response = $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'region' => 'props']))->assertOk();

    expect($response->json('version'))->toMatch('/^[A-Za-z0-9_-]{16}$/');
    $response->assertExactJson([
        'protocol' => 1,
        'version' => $response->json('version'),
        'made_at' => $response->json('made_at'),
        'region' => 'props',
        'fragment' => ['type' => 'button', 'props' => ['label' => 'Go'], 'on_press' => ['call' => 'go', 'args' => [1]]],
    ]);
});

it('serialises nodes into the protocol shape', function () {
    $node = Node::make('column')->key('x')->class('p-4')->props(['a' => 1])
        ->onPress('tap', 3, 'x')->onLongPressClient('hold')->onDoubleTap('dbl')->onChangeClient('c', ['a' => [1]])->onSubmit('s', null, 1.5, true)
        ->children(Node::text('Hi'), [Node::spacer(), Node::divider()], ['type' => 'button', 'props' => ['label' => 'Go']]);

    expect(json_decode(json_encode($node), true))->toBe([
        'type' => 'column',
        'key' => 'x',
        'props' => ['a' => 1],
        'class' => 'p-4',
        'on_press' => ['call' => 'tap', 'args' => [3, 'x']],
        'on_long_press' => ['call' => 'hold', 'args' => [], 'target' => 'client'],
        'on_double_tap' => ['call' => 'dbl', 'args' => []],
        'on_change' => ['call' => 'c', 'args' => [['a' => [1]]], 'target' => 'client'],
        'on_submit' => ['call' => 's', 'args' => [null, 1.5, true]],
        'children' => [
            ['type' => 'text', 'props' => ['text' => 'Hi']],
            ['type' => 'spacer'],
            ['type' => 'divider'],
            ['type' => 'button', 'props' => ['label' => 'Go']],
        ],
    ]);

    expect(Node::button('Go')->onPressClient('celebrate')->toArray()['on_press'])
        ->toBe(['call' => 'celebrate', 'args' => [], 'target' => 'client']);
});

it('only registers real server components', function () {
    expect(fn () => Asgard::component('app.x', stdClass::class))->toThrow(InvalidArgumentException::class);
    expect(fn () => Asgard::component('App\\X', HomeScreen::class))->toThrow(InvalidArgumentException::class);
});
