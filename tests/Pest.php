<?php

use Native\Asgard\Security\Wire;
use Native\Asgard\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function asgardBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.home',
        'regions' => ['greeting'],
        'state' => ['name' => 'Simon', 'platform' => 'ios'],
    ], $overrides);
}

function subscription(array $overrides = []): array
{
    return array_merge([
        'id' => 's1',
        'component' => 'app.native.screens.home',
        'regions' => ['greeting', 'list'],
        'state' => ['name' => 'Simon', 'platform' => 'ios'],
    ], $overrides);
}

/**
 * A stream body with one subscription. Overrides for a subscription's own
 * fields (id, component, regions, state, versions) go to that subscription,
 * the rest to the top level. Pass $subscriptions to send several.
 */
function streamBody(array $overrides = [], ?array $subscriptions = null): array
{
    $own = array_intersect_key($overrides, array_flip(['id', 'component', 'regions', 'state', 'versions']));

    return array_merge([
        'protocol' => 1,
        'subscriptions' => $subscriptions ?? [subscription($own)],
    ], array_diff_key($overrides, $own));
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

/**
 * Every signal and fragment event, decrypted. A signal has no fragment.
 *
 * @return list<array{id: string, event: string, subscription: string, region: string, version: string, fragment: mixed}>
 */
function sseEvents(string $content): array
{
    return array_values(array_map(
        fn ($e) => ['id' => $e['id'], 'event' => $e['event'], 'subscription' => $e['data']['subscription'], 'region' => $e['data']['region'], 'version' => $e['data']['version'], 'fragment' => $e['data']['fragment'] ?? null],
        array_filter(sseBlocks($content), fn ($b) => is_array($b) && in_array($b['event'], ['signal', 'fragment'], true)),
    ));
}
