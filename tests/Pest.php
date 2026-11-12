<?php

use Native\Asgard\Security\Wire;
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

function streamBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.home',
        'regions' => ['greeting', 'list'],
        'state' => ['name' => 'Simon'],
        'platform' => 'ios',
    ], $overrides);
}

/**
 * Splits an SSE body into its blocks. Fragment events come back as
 * ['id' => ..., 'event' => ..., 'data' => decoded JSON], after their
 * signature is checked against the request that opened the stream and
 * their body is decrypted, as a client would.
 *
 * @return list<array<string, mixed>|string>
 */
function sseBlocks(string $content): array
{
    $blocks = [];
    foreach (array_filter(explode("\n\n", $content), fn ($b) => $b !== '') as $block) {
        if (! str_starts_with($block, 'id: ')) {
            $blocks[] = $block;

            continue;
        }

        $fields = [];
        foreach (explode("\n", $block) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $fields[$name] = $value;
        }

        $stream = TestCase::$stream;
        $fields['data'] = json_decode(
            Wire::openEvent($stream['keys'], $stream['id'], $stream['signature'], $fields['id'], $fields['event'], $fields['data']),
            true, 512, JSON_THROW_ON_ERROR,
        );
        $blocks[] = $fields;
    }

    return $blocks;
}

/** @return list<array{id: string, region: string, fragment: mixed}> */
function sseFragments(string $content): array
{
    return array_values(array_map(
        fn ($e) => ['id' => $e['id'], 'region' => $e['data']['region'], 'fragment' => $e['data']['fragment']],
        array_filter(sseBlocks($content), fn ($b) => is_array($b) && $b['event'] === 'fragment'),
    ));
}
