<?php

use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\HomeScreen;

/** A value wrapped in $levels arrays: nestedArg(2) is [[1]]. */
function nestedArg(int $levels): array
{
    $value = 1;
    for ($i = 0; $i < $levels; $i++) {
        $value = [$value];
    }

    return $value;
}

function actionBody(array $action, array $overrides = []): array
{
    return asgardBody(array_merge(['state' => ['name' => 'Simon', 'count' => 2], 'action' => $action], $overrides));
}

it('runs a marked action and renders the region it changed', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => [3, '!']]))
        ->assertOk()
        ->assertExactJson([
            'protocol' => 1,
            'region' => 'greeting',
            'fragment' => [
                'type' => 'column',
                'class' => 'p-4 gap-2',
                'children' => [
                    ['type' => 'text', 'key' => 'hello', 'props' => ['text' => 'Hello, Simon!']],
                    ['type' => 'text', 'props' => ['text' => 'Role: guest']],
                    ['type' => 'text', 'props' => ['text' => 'Count: 5']],
                    ['type' => 'text', 'props' => ['text' => 'Platform: ios']],
                ],
            ],
        ]);

    expect(HomeScreen::$calls)->toBe(['shuffle']);
});

it('runs an action over POST with the method header', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => []]), 'POST', ['X-Asgard-Method' => 'QUERY'])
        ->assertOk()
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 3');
});

it('accepts an explicit server target', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => [1], 'target' => 'server']))
        ->assertOk()
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 3');
});

it('keeps action changes to this request only', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => [10]]))
        ->assertOk()
        ->assertJsonMissingPath('state')
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 12');

    $this->asgard(asgardBody(['state' => ['count' => 2]]))
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 2');
});

it('hydrates before the action and still keeps Locked props from the client', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => []], ['state' => ['role' => 'admin', 'count' => 1]]))
        ->assertOk()
        ->assertJsonPath('fragment.children.1.props.text', 'Role: guest')
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 2');

    // The server itself may still change a Locked prop.
    $this->asgard(actionBody(['call' => 'promote', 'args' => []]))
        ->assertJsonPath('fragment.children.1.props.text', 'Role: admin');
});

it('passes args positionally to variadic and loosely typed params', function () {
    $this->asgard(actionBody(['call' => 'sum', 'args' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]]))
        ->assertOk()
        ->assertJsonPath('fragment.children.2.props.text', 'Count: 136');

    $this->asgard(actionBody(['call' => 'anything', 'args' => [['a' => [1]], 'x', 2, 'id']]))
        ->assertOk();

    expect(HomeScreen::$calls)->toBe(['sum', 'anything']);
});

it('refuses methods that are not actions with one 404', function (string $call, array $args = []) {
    $this->asgard(actionBody(['call' => $call, 'args' => $args]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_action']);

    expect(HomeScreen::$calls)->toBe([]);
})->with([
    'unmarked public method' => ['unmarked'],
    'unmarked region method' => ['greeting'],
    'region that is also marked as an action' => ['both'],
    'public method without attributes' => ['secret'],
    'ServerComponent method' => ['setAsgardContext', ['x', 'ios']],
    'ServerComponent protected method' => ['platform'],
    'static' => ['staticAction'],
    'protected' => ['protectedAction'],
    'private' => ['privateAction'],
    'magic __invoke' => ['__invoke'],
    'magic __call' => ['__call', ['x', []]],
    'missing method that __call would catch' => ['doesNotExist'],
    'constructor' => ['__construct'],
    'wrong case' => ['SHUFFLE'],
    'class-typed param' => ['withModel', [[]]],
    'interface-typed param' => ['withInterface', [[]]],
    'self-typed param' => ['withSelf'],
    'callable param' => ['withCallable', ['phpinfo']],
    'object param' => ['withObject', [[]]],
    'by-reference param' => ['withReference', [1]],
    'union with a class type' => ['withUnion', [1]],
    'too few args' => ['needsTwo', [1]],
    'too many args' => ['needsTwo', [1, 2, 3]],
    'too many args for a no-arg action' => ['promote', [1]],
    'extra args into a non-scalar variadic' => ['lists', [[1], [2]]],
]);

it('refuses actions inherited from a parent class', function () {
    $this->asgard(actionBody(['call' => 'inherited', 'args' => []], ['component' => 'app.native.screens.child', 'region' => 'count']))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_action']);

    expect(ChildScreen::$calls)->toBe([]);
});

it('refuses unmarked trait methods but runs marked ones', function () {
    $body = fn ($call) => actionBody(['call' => $call, 'args' => []], ['component' => 'app.native.screens.child', 'region' => 'count', 'state' => []]);

    $this->asgard($body('fromTrait'))->assertNotFound()->assertExactJson(['error' => 'unknown_action']);
    $this->asgard($body('markedInTrait'))->assertOk();
    $this->asgard($body('bump'))->assertOk()->assertJsonPath('fragment.props.text', 'Count: 1');

    expect(ChildScreen::$calls)->toBe(['markedInTrait', 'bump']);
});

it('only knows actions declared on the class itself', function () {
    expect(array_keys(ComponentMetadata::for(HomeScreen::class)->actions))
        ->toBe(['shuffle', 'promote', 'needsTwo', 'sum', 'lists', 'anything']);
    expect(array_keys(ComponentMetadata::for(ChildScreen::class)->actions))
        ->toBe(['bump', 'markedInTrait']);
});

it('still needs a known region when running an action', function () {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => []], ['region' => 'nope']))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);

    expect(HomeScreen::$calls)->toBe([]);
});

it('rejects a badly shaped action with 422', function (mixed $action) {
    $this->asgard(asgardBody(['action' => $action]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');

    expect(HomeScreen::$calls)->toBe([]);
})->with([
    'null' => [null],
    'string' => ['shuffle()'],
    'list' => [['shuffle', []]],
    'missing args' => [['call' => 'shuffle']],
    'missing call' => [['args' => []]],
    'args is an object' => [['call' => 'shuffle', 'args' => ['by' => 1]]],
    'args is a string' => [['call' => 'shuffle', 'args' => '1']],
    'unknown field' => [['call' => 'shuffle', 'args' => [], 'method' => 'x']],
    'call with a namespace' => [['call' => 'App\\Foo::bar', 'args' => []]],
    'call with parens' => [['call' => 'shuffle()', 'args' => []]],
    'call with a trailing newline' => [['call' => "shuffle\n", 'args' => []]],
    'call starting with a digit' => [['call' => '1shuffle', 'args' => []]],
    'call too long' => [['call' => str_repeat('a', 101), 'args' => []]],
    'call is not a string' => [['call' => 5, 'args' => []]],
    'too many args' => [['call' => 'sum', 'args' => range(1, 17)]],
    'args nested too deep' => [['call' => 'anything', 'args' => [nestedArg(5)]]],
    'target client' => [['call' => 'shuffle', 'args' => [], 'target' => 'client']],
    'target other' => [['call' => 'shuffle', 'args' => [], 'target' => 'device']],
    'target null' => [['call' => 'shuffle', 'args' => [], 'target' => null]],
]);

it('accepts args nested exactly at the depth limit', function () {
    $this->asgard(actionBody(['call' => 'anything', 'args' => [nestedArg(4)]]))
        ->assertOk();
});

it('turns an arg that does not fit the parameter type into a 422', function (array $args) {
    $this->asgard(actionBody(['call' => 'shuffle', 'args' => $args]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');

    expect(HomeScreen::$calls)->toBe([]);
})->with([
    'string into int' => [['x']],
    'numeric string into int' => [['5']],
    'float into int' => [[1.5]],
    'bool into int' => [[true]],
    'null into int' => [[null]],
    'array into int' => [[[1]]],
    'int into string' => [[1, 2]],
]);

it('type checks variadic args too', function () {
    $this->asgard(actionBody(['call' => 'sum', 'args' => [1, '2']]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');
});

it('validates callbacks at build time', function (Closure $build) {
    expect($build)->toThrow(InvalidFragment::class);
})->with([
    'expression string' => [fn () => Node::button('Go')->onPress('go()')],
    'namespaced name' => [fn () => Node::button('Go')->onPressClient('App\\go')],
    'empty name' => [fn () => Node::button('Go')->onLongPress('')],
    'object arg' => [fn () => Node::button('Go')->onDoubleTap('go', new stdClass)],
    'closure arg' => [fn () => Node::button('Go')->onChange('go', fn () => 1)],
    'non-finite float' => [fn () => Node::button('Go')->onSubmit('go', INF)],
    'too many args' => [fn () => Node::button('Go')->onPress('go', ...range(1, 17))],
    'too deep' => [fn () => Node::button('Go')->onPress('go', nestedArg(5))],
    'named args' => [fn () => Node::button('Go')->onPress('go', by: 1)],
]);

it('rejects callbacks in a fragment that are not the object shape', function (mixed $callback) {
    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'region' => 'callback', 'state' => ['callback' => $callback]]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment');
})->with([
    'expression string' => ['go()'],
    'bare name' => ['go'],
    'int' => [1],
    'bool' => [true],
    'empty object' => [[]],
    'list' => [['go', []]],
    'missing args' => [['call' => 'go']],
    'missing call' => [['args' => []]],
    'bad name' => [['call' => 'go()', 'args' => []]],
    'args object' => [['call' => 'go', 'args' => ['a' => 1]]],
    'too many args' => [['call' => 'go', 'args' => range(1, 17)]],
    'too deep' => [['call' => 'go', 'args' => [nestedArg(5)]]],
    'unknown target' => [['call' => 'go', 'args' => [], 'target' => 'device']],
    'extra field' => [['call' => 'go', 'args' => [], 'expr' => 'x']],
]);

it('passes valid callback objects through a fragment', function (array $callback, array $expected) {
    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'region' => 'callback', 'state' => ['callback' => $callback]]))
        ->assertOk()
        ->assertJsonPath('fragment.on_press', $expected);
})->with([
    'server' => [['call' => 'go', 'args' => [1, 'x']], ['call' => 'go', 'args' => [1, 'x']]],
    'explicit server is dropped' => [['call' => 'go', 'args' => [], 'target' => 'server'], ['call' => 'go', 'args' => []]],
    'client' => [['call' => 'go', 'args' => [], 'target' => 'client'], ['call' => 'go', 'args' => [], 'target' => 'client']],
]);
