<?php

use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\HomeScreen;

// One #[ServerAction] allowlist serves both a fragment's tap and a device's #[Server] call.

it('runs a #[ServerAction] from a tap in a fragment', function () {
    $this->asgard(asgardBody([
        'state' => ['name' => 'Simon', 'count' => 2, 'platform' => 'ios'],
        'action' => ['call' => 'shuffle', 'args' => [3]],
    ]))
        ->assertOk()
        ->assertJsonPath('fragments.greeting.fragment.children.2.props.text', 'Count: 5');

    expect(HomeScreen::$calls)->toBe(['shuffle']);
});

it('runs the same #[ServerAction] from a device call', function () {
    $this->asgard([
        'protocol' => 1,
        'component' => 'app.native.screens.home',
        'method' => 'shuffle',
        'args' => [3],
        'state' => ['name' => 'Simon', 'count' => 2, 'platform' => 'ios'],
    ], 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['count' => 5]]);

    expect(HomeScreen::$calls)->toBe(['shuffle']);
});

it('refuses an unmarked public method from a tap', function () {
    $this->asgard(asgardBody(['action' => ['call' => 'unmarked', 'args' => []]]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_action']);

    expect(HomeScreen::$calls)->toBe([]);
});

it('refuses an unmarked public method from a device call', function () {
    $this->asgard([
        'protocol' => 1,
        'component' => 'app.native.screens.home',
        'method' => 'unmarked',
        'args' => [],
        'state' => ['name' => 'Simon', 'platform' => 'ios'],
    ], 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);

    expect(HomeScreen::$calls)->toBe([]);
});

it('keeps one allowlist for both endpoints', function () {
    $metadata = ComponentMetadata::for(HomeScreen::class);

    expect($metadata->serverActions)->toHaveKey('shuffle')
        ->not->toHaveKey('unmarked')
        ->and(property_exists($metadata, 'actions'))->toBeFalse()
        ->and(property_exists($metadata, 'servers'))->toBeFalse();
});
