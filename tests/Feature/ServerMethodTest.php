<?php

use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Wire;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\Fixtures\ServerMethodScreen;

function callBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.server_methods',
        'method' => 'refreshChart',
        'args' => ['30d'],
        'state' => ['range' => '7d', 'loading' => true, 'chart' => [], 'platform' => 'ios'],
    ], $overrides);
}

it('runs a #[Server] method and answers with only the props it changed', function () {
    $this->asgard(callBody(), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson([
            'protocol' => 1,
            'delta' => ['range' => '30d', 'chart' => ['30d', 'ios']],
        ]);

    expect(ServerMethodScreen::$calls)->toBe(['refreshChart']);
});

it('reads the platform from state', function () {
    $this->asgard(callBody(), 'QUERY', [], 'call')
        ->assertOk()
        ->assertJsonPath('delta.chart', ['30d', 'ios']);
});

it('refuses a top-level platform, like any unknown member', function () {
    $this->asgard(callBody(['platform' => 'ios']), 'QUERY', [], 'call')->assertStatus(422);
});

it('runs over POST with the method header', function () {
    $this->asgard(callBody(['method' => 'rollDie', 'args' => []]), 'POST', ['X-Asgard-Method' => 'QUERY'], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['roll' => 4]]);
});

it('answers an empty delta as an object', function () {
    $response = $this->asgard(callBody(['method' => 'sameAgain', 'args' => [], 'state' => ['chart' => [1, 2]]]), 'QUERY', [], 'call')
        ->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"delta":{}}');
});

it('compares against the hydrated state, not the class defaults', function () {
    // The device already holds 30d, so setting it again is not a change.
    $response = $this->asgard(callBody(['state' => ['range' => '30d', 'chart' => ['30d', 'ios'], 'platform' => 'ios']]), 'QUERY', [], 'call')
        ->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"delta":{}}');
});

it('sends back a Locked prop the server changed', function () {
    $this->asgard(callBody(['method' => 'upgrade', 'args' => [], 'state' => ['plan' => 'enterprise']]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['plan' => 'pro']]);
});

it('counts a typed prop the method fills in as changed', function () {
    $this->asgard(callBody(['method' => 'fill', 'args' => []]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['filled' => 3]]);
});

it('refuses a prop that is not a plain JSON value', function (string $method) {
    $this->asgard(callBody(['method' => $method, 'args' => []]), 'QUERY', [], 'call')
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_delta');
})->with(['leaveAnObject', 'leaveNan']);

it('hides why a delta was refused outside debug', function () {
    config()->set('app.debug', false);

    $this->asgard(callBody(['method' => 'leaveAnObject', 'args' => []]), 'QUERY', [], 'call')
        ->assertStatus(500)
        ->assertExactJson(['error' => 'invalid_delta']);
});

it('refuses methods that are not #[Server] methods with one 404', function (string $method, array $args = []) {
    $this->asgard(callBody(['method' => $method, 'args' => $args]), 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);

    expect(ServerMethodScreen::$calls)->toBe([]);
})->with([
    'unmarked public method' => ['unmarked'],
    'an #[Action] only' => ['actionOnly'],
    'a region that is also marked' => ['chartRegion'],
    'static' => ['staticServer'],
    'protected' => ['protectedServer'],
    'private' => ['privateServer'],
    'object param' => ['withObject', [[]]],
    'ServerComponent method' => ['setAsgardContext', ['x']],
    'ServerComponent protected method' => ['region'],
    'magic __construct' => ['__construct'],
    'missing method' => ['doesNotExist'],
    'wrong case' => ['ROLLDIE'],
    'too few args' => ['needsTwo', [1]],
    'too many args' => ['needsTwo', [1, 2, 3]],
    'args for a no-arg method' => ['rollDie', [1]],
]);

it('refuses an #[Action] on another component as a server method', function () {
    $this->asgard(callBody(['component' => 'app.native.screens.home', 'method' => 'shuffle', 'args' => []]), 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);

    expect(HomeScreen::$calls)->toBe([]);
});

it('refuses an unknown component', function () {
    $this->asgard(callBody(['component' => 'app.native.screens.nope']), 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);
});

it('refuses an arg that does not fit its param', function () {
    $this->asgard(callBody(['args' => [30]]), 'QUERY', [], 'call')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request')
        ->assertJsonPath('message', 'args[0] does not fit the parameter type.');

    expect(ServerMethodScreen::$calls)->toBe([]);
});

it('refuses a malformed call body', function (array $overrides, ?string $drop = null) {
    $body = callBody($overrides);
    if ($drop !== null) {
        unset($body[$drop]);
    }

    $this->asgard($body, 'QUERY', [], 'call')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');

    expect(ServerMethodScreen::$calls)->toBe([]);
})->with([
    'no method' => [[], 'method'],
    'no args' => [[], 'args'],
    'args as an object' => [['args' => ['a' => 1]]],
    'a bad method name' => [['method' => 'refresh-chart']],
    'an unknown field' => [['region' => 'chart']],
    'state that does not fit' => [['state' => ['range' => 7]]],
    'state with an object-shaped list' => [['state' => [1, 2]]],
    'protocol 2' => [['protocol' => 2]],
]);

it('refuses a call without a nonce, early and unsigned', function () {
    $response = $this->asgard(callBody(), 'QUERY', [], 'call', ['nonce' => false])
        ->assertForbidden()
        ->assertJsonPath('error', 'nonce_required');

    expect($response->headers->has('Signature'))->toBeFalse()
        ->and(ServerMethodScreen::$calls)->toBe([]);
});

it('refuses a call without a nonce before it reads the body', function () {
    $this->asgard('not json at all', 'QUERY', [], 'call', ['nonce' => false])
        ->assertForbidden()
        ->assertJsonPath('error', 'nonce_required');
});

it('runs a call once per nonce and refuses the replay', function () {
    $session = $this->handshake();
    $url = parse_url($this->prepareUrlForRequest('/_asgard/call'));
    $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

    $sealed = Wire::sealRequest(
        $session['keys'], $session['id'], 'QUERY', $authority, '/_asgard/call',
        json_encode(callBody(['method' => 'rollDie', 'args' => []])), [], now()->getTimestamp(),
        Base64Url::encode(random_bytes(16)),
    );

    $this->sendSealed($sealed, 'QUERY', 'call')->assertOk();
    $this->sendSealed($sealed, 'QUERY', 'call')->assertForbidden();

    expect(ServerMethodScreen::$calls)->toBe(['rollDie']);
});

it('refuses an unsealed call', function () {
    $this->call('QUERY', '/_asgard/call', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(callBody()))
        ->assertStatus(419);

    expect(ServerMethodScreen::$calls)->toBe([]);
});
