<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Node;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\SessionStore;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\TestCase;

/*
 * The fragment rules of Asgard protocol Draft 2: only core types and the
 * ones the client lists in `elements` (§6.1, §9.3, §9.7), at most 64 levels
 * with the root at 1 (§9.1), and node members this version does not know
 * are ignored (§5.3).
 */

function elementsHandshake(mixed $elements): TestResponse
{
    [, $public] = Ecdh::generate();

    return test()->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'key' => TestCase::KEY, 'protocol' => 1, 'accepts' => ['tree/json'], 'client_public' => Base64Url::encode($public),
        'elements' => $elements,
    ]));
}

function typed(string $type): array
{
    return asgardBody(['component' => 'app.native.screens.bad', 'region' => 'typed', 'state' => ['type' => $type]]);
}

/** A column nested $depth levels deep, counting the root as 1. */
function nested(int $depth): array
{
    $node = ['type' => 'text'];
    for ($i = 1; $i < $depth; $i++) {
        $node = ['type' => 'column', 'children' => [$node]];
    }

    return $node;
}

// Handshake

it('keeps the element types the client lists with its session', function () {
    $response = elementsHandshake(['list_item', 'toggle'])->assertOk();

    expect(Cache::get(SessionStore::PREFIX.$response->json('session'))['elements'])->toBe(['list_item', 'toggle']);
});

it('takes a handshake with no elements, or an empty list, as core types only', function () {
    $this->handshake();
    expect(Cache::get(SessionStore::PREFIX.$this->asgardSession['id'])['elements'])->toBe([]);

    $response = elementsHandshake([])->assertOk();
    expect(Cache::get(SessionStore::PREFIX.$response->json('session'))['elements'])->toBe([]);
});

it('drops chrome, region and core types from the list it keeps', function () {
    $response = elementsHandshake(['top_bar', 'list_item', 'server_region', 'column', 'fab'])->assertOk();

    expect(Cache::get(SessionStore::PREFIX.$response->json('session'))['elements'])->toBe(['list_item']);
});

it('refuses a malformed elements list', function (mixed $elements) {
    elementsHandshake($elements)
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');
})->with([
    'a string' => ['list_item'],
    'an object' => [['a' => 'list_item']],
    'a number in it' => [['list_item', 7]],
    'camelCase' => [['listItem']],
    'kebab-case' => [['list-item']],
    'leading digit' => [['1item']],
    'empty name' => [['']],
    'repeated' => [['toggle', 'toggle']],
    'too long a name' => [[str_repeat('a', 65)]],
    'too many' => [array_map(fn ($i) => "type_{$i}", range(1, 257))],
]);

it('takes 256 types of up to 64 characters', function () {
    elementsHandshake([...array_map(fn ($i) => "type_{$i}", range(1, 255)), str_repeat('a', 64)])->assertOk();
});

// Types

it('renders every core type for a client that listed nothing', function (string $type) {
    $this->asgard(typed($type))->assertOk()->assertJsonPath('fragment.type', $type);
})->with(FragmentValidator::CORE_TYPES);

it('refuses a type the client did not list', function () {
    $this->handshake(['toggle']);

    $this->asgard(typed('list_item'))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment')
        ->assertJsonPath('message', fn ($m) => str_contains($m, '[list_item] is not a core type'));
});

it('renders a type the client listed', function () {
    $this->handshake(['list_item']);

    $this->asgard(typed('list_item'))->assertOk()->assertJsonPath('fragment.type', 'list_item');
});

it('never renders chrome or a region, even when the client lists it', function () {
    $this->handshake(['list_item']);
    // The handshake drops these, so force them into the session as a buggy server might.
    $id = $this->asgardSession['id'];
    Cache::put(SessionStore::PREFIX.$id, ['elements' => ['top_bar', 'server_region']] + Cache::get(SessionStore::PREFIX.$id), 60);

    foreach (['top_bar', 'server_region'] as $type) {
        $this->asgard(typed($type))
            ->assertStatus(500)
            ->assertJsonPath('message', fn ($m) => str_contains($m, "[{$type}] is not allowed"));
    }

    expect(fn () => FragmentValidator::validate(Node::make('fab'), ['fab']))->toThrow(InvalidFragment::class, 'not allowed');
});

it('checks each node, not just the root', function () {
    expect(fn () => FragmentValidator::validate(Node::column(Node::row(Node::make('toggle')))))
        ->toThrow(InvalidFragment::class, 'fragment.children[0].children[0]: element type [toggle]');

    expect(FragmentValidator::validate(Node::column(Node::row(Node::make('toggle'))), ['toggle'])['children'][0]['children'][0])
        ->toBe(['type' => 'toggle']);
});

it('checks a shared region on the stream against that stream\'s client', function () {
    config()->set('asgard.stream.lifetime', 1);
    $this->app->instance(StreamClock::class, new FakeClock);

    $body = streamBody(['component' => 'app.native.screens.bad', 'regions' => ['typed'], 'state' => ['type' => 'list_item']]);

    // The first stream renders it and the answer is cached...
    $this->handshake(['list_item']);
    [, $event] = sseBlocks($this->asgardStream($body)->assertOk()->streamedContent());
    expect($event['data']['fragment'])->toBe(['type' => 'list_item']);

    // ...but a client that cannot render it does not get it from the cache.
    $this->handshake();
    $this->asgardStream($body)->assertStatus(500)->assertJsonPath('error', 'invalid_fragment');
});

// Depth

it('takes a fragment 64 levels deep, counting the root as 1', function () {
    expect(FragmentValidator::validate(nested(64)))->toBe(nested(64));
});

it('refuses a fragment 65 levels deep', function () {
    expect(fn () => FragmentValidator::validate(nested(65)))->toThrow(InvalidFragment::class, 'deeper than 64 levels');
    expect(fn () => FragmentValidator::validate([nested(1), nested(65)]))->toThrow(InvalidFragment::class, 'deeper than 64 levels');
});

// Unknown members

it('drops node members it does not know, and sends the rest', function () {
    expect(FragmentValidator::validate([
        'type' => 'column',
        'key' => 7,
        'on_hover' => ['call' => 'x', 'args' => []],
        'style' => ['color' => 'red'],
        'children' => [['type' => 'text', 'props' => ['text' => 'Hi'], 'animate' => true]],
    ]))->toBe([
        'type' => 'column',
        'key' => 7,
        'children' => [['type' => 'text', 'props' => ['text' => 'Hi']]],
    ]);
});

it('still refuses a slot with a member it does not know', function () {
    expect(fn () => FragmentValidator::validate(['type' => 'button', 'on_press' => ['call' => 'go', 'args' => [], 'then' => 'x']]))
        ->toThrow(InvalidFragment::class, 'unknown callback field');

    expect(fn () => FragmentValidator::validate(['type' => 'button', 'on_press' => ['navigate' => '/x', 'modal' => true]]))
        ->toThrow(InvalidFragment::class, 'unknown navigate field');
});

// Keys and text runs

it('refuses siblings that share a key, comparing keys as strings', function () {
    expect(fn () => FragmentValidator::validate(Node::column(Node::text('a')->key(7), Node::text('b')->key('7'))))
        ->toThrow(InvalidFragment::class, 'two siblings share the key [7]');

    expect(fn () => FragmentValidator::validate([Node::text('a')->key('x'), Node::text('b')->key('x')]))
        ->toThrow(InvalidFragment::class, 'share the key [x]');

    // The same key at different levels, or no key at all, is fine.
    expect(FragmentValidator::validate(Node::column(Node::row(Node::text('a')->key('x'))->key('x'), Node::text('b'), Node::text('c'))))
        ->toHaveKey('children');
});

it('takes only text nodes as the children of a text node', function () {
    expect(FragmentValidator::validate(Node::text('Hello ')->children(Node::text('world')->props(['fontWeight' => 6])))['children'][0]['type'])
        ->toBe('text');

    expect(fn () => FragmentValidator::validate(Node::text('Hello')->children(Node::make('image'))))
        ->toThrow(InvalidFragment::class, "a text node's children must be text nodes");
});
