<?php

use Native\Asgard\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function asgardBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.home',
        'region' => 'greeting',
        'state' => ['name' => 'Simon'],
        'platform' => 'ios',
    ], $overrides);
}
