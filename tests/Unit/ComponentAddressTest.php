<?php

use Native\Asgard\ComponentRegistry;
use Native\Asgard\Tests\Fixtures\HomeScreen;

it('accepts addresses whose segments start with a letter', function (string $address) {
    expect(new ComponentRegistry([$address => HomeScreen::class]))->toBeInstanceOf(ComponentRegistry::class);
})->with(['home', 'component-name', 'reports.sales-chart', 'app.native.screens.home', 'a_b-c.d1']);

it('refuses addresses that are not dot-joined lower-case segments', function (string $address) {
    new ComponentRegistry([$address => HomeScreen::class]);
})->with(['', 'Home', '-home', '1home', 'home.', '.home', 'home..x', 'home.-x', 'home screen', 'app\\home'])
    ->throws(InvalidArgumentException::class);
