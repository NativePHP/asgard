<?php

use Illuminate\Support\Facades\Facade;
use Native\Asgard\Asgard as AsgardManager;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Node;
use Native\Asgard\PendingPatch;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\ChannelScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\FeedScreen;
use Native\Asgard\Tests\Fixtures\PromoScreen;
use Native\Asgard\Tests\Fixtures\RowsScreen;
use Native\Asgard\Tests\TestCase;

const FEED = 'app.native.screens.feed';

beforeEach(function () {
    config()->set('asgard.stream.lifetime', 3);
    config()->set('asgard.stream.poll_ms', 500);

    $this->clock = new FakeClock;
    $this->app->instance(StreamClock::class, $this->clock);

    Asgard::component(FEED, FeedScreen::class);
});

function feedStream(array $overrides = []): array
{
    return streamBody(array_merge(['component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'ios']], $overrides));
}

/** The version a render of the feed region answers with now. */
function feedVersion(TestCase $test): string
{
    return $test->asgard(asgardBody(['component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'ios']]))->assertOk()->json('fragments.feed.version');
}

/** A feed stream for a device that holds the current version, so connect sends nothing. */
function heldFeedStream(TestCase $test, array $overrides = []): string
{
    return $test->asgardStream(feedStream(['versions' => ['feed' => feedVersion($test)], ...$overrides]))->streamedContent();
}

/** Every event, decrypted, as [event, data]. */
function allEvents(string $content): array
{
    return array_values(array_map(
        fn ($b) => [$b['event'], $b['data']],
        array_filter(sseBlocks($content), fn ($b) => is_array($b)),
    ));
}

/** The data of every patch event, in order. */
function patchData(string $content): array
{
    return array_values(array_map(fn ($e) => $e[1], array_filter(allEvents($content), fn ($e) => $e[0] === 'patch')));
}

function item(string $key): Node
{
    return Node::text("Item {$key}")->key($key);
}

/** Rebuild the push store and the facade with a patch log of this size. */
function usePatchLog(TestCase $test, int $size): void
{
    config()->set('asgard.stream.patch_log', $size);
    app()->forgetInstance(PushVersions::class);
    app()->forgetInstance(AsgardManager::class);
    Facade::clearResolvedInstances();
    Asgard::component(FEED, FeedScreen::class);
}

// ── The event ────────────────────────────────────────

it('sends a prepend as a patch event from the held version to the new one', function () {
    $held = feedVersion($this);
    $this->clock->onSleep[1] = fn () => Asgard::patch(FeedScreen::class, 'feed')->prepend(item('c'), into: 'items', keep: 1000);

    $content = $this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent();

    $events = allEvents($content);
    expect($events)->toHaveCount(1)
        ->and($events[0][0])->toBe('patch')
        ->and($events[0][1])->toBe([
            'protocol' => 1, 'subscription' => 's1', 'region' => 'feed',
            'from' => $held, 'version' => feedVersion($this),
            'patch' => ['op' => 'prepend', 'target' => 'items', 'nodes' => [['type' => 'text', 'key' => 'c', 'props' => ['text' => 'Item c']]], 'keep' => 1000],
        ]);

    // The plaintext keeps that order, with the patch last.
    expect(array_keys($events[0][1]))->toBe(['protocol', 'subscription', 'region', 'from', 'version', 'patch']);
});

it('sends append and remove, with keys and targets as strings', function () {
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->append([item('c'), item('d')], into: 'items', keep: 500);
    $this->clock->onSleep[2] = fn () => Asgard::patch(FEED)->remove(into: 7, keys: [42, '43']);
    $this->clock->onSleep[3] = fn () => Asgard::patch(FEED)->prepend(['type' => 'text', 'key' => 9, 'props' => ['text' => 'nine']], into: 'items');

    $patches = array_column(patchData(heldFeedStream($this)), 'patch');

    expect($patches)->toBe([
        ['op' => 'append', 'target' => 'items', 'nodes' => [
            ['type' => 'text', 'key' => 'c', 'props' => ['text' => 'Item c']],
            ['type' => 'text', 'key' => 'd', 'props' => ['text' => 'Item d']],
        ], 'keep' => 500],
        ['op' => 'remove', 'target' => '7', 'keys' => ['42', '43']],
        ['op' => 'prepend', 'target' => 'items', 'nodes' => [['type' => 'text', 'key' => 9, 'props' => ['text' => 'nine']]]],
    ]);
});

it('renders a patch once, at push time, into the push store', function () {
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');

    $entry = cache()->get(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 1));

    expect($entry['patch'])->toBeString()
        ->and(json_decode($entry['patch'], true)['nodes'][0]['key'])->toBe('c')
        ->and($entry['types'])->toBe(['text']);
});

it('chains several patches between two polls, one event each, in order', function () {
    $held = feedVersion($this);
    $this->clock->onSleep[1] = function () {
        foreach (['c', 'd', 'e'] as $key) {
            Asgard::patch(FEED)->prepend(item($key), into: 'items', keep: 1000);
        }
    };

    $patches = patchData($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent());

    expect(array_map(fn ($p) => $p['patch']['nodes'][0]['key'], $patches))->toBe(['c', 'd', 'e'])
        ->and($patches[0]['from'])->toBe($held)
        ->and($patches[1]['from'])->toBe($patches[0]['version'])
        ->and($patches[2]['from'])->toBe($patches[1]['version'])
        ->and($patches[2]['version'])->toBe(feedVersion($this))
        ->and(array_unique(array_column($patches, 'version')))->toHaveCount(3);
});

it('sends one patch per poll at a fast poll rate', function () {
    config()->set('asgard.stream.poll_ms', 16);
    config()->set('asgard.stream.lifetime', 1);
    foreach (range(1, 30) as $sleep) {
        $this->clock->onSleep[$sleep] = fn () => Asgard::patch(FEED)->prepend(item("n{$sleep}"), into: 'items', keep: 1000);
    }

    $events = allEvents(heldFeedStream($this));

    expect(array_unique(array_column($events, 0)))->toBe(['patch'])
        ->and($events)->toHaveCount(30);
});

it('sends a patch to every stream watching the region', function () {
    $held = feedVersion($this);
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(item('c'), into: 'items');

    $patches = patchData($this->asgardStream(streamBody([], [
        subscription(['id' => 'one', 'component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'ios'], 'versions' => ['feed' => $held]]),
        subscription(['id' => 'two', 'component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'android'], 'versions' => ['feed' => $held]]),
    ]))->streamedContent());

    expect(array_column($patches, 'subscription'))->toBe(['one', 'two'])
        ->and($patches[0]['patch'])->toBe($patches[1]['patch']);
});

it('replays the patches on connect to a device a few patches behind', function () {
    $held = feedVersion($this);
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');
    Asgard::patch(FEED)->prepend(item('d'), into: 'items');

    $events = allEvents($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent());

    expect(array_column($events, 0))->toBe(['patch', 'patch'])
        ->and($events[0][1]['from'])->toBe($held)
        ->and($events[1][1]['from'])->toBe($events[0][1]['version'])
        ->and($events[1][1]['version'])->toBe(feedVersion($this))
        ->and(array_map(fn ($e) => $e[1]['patch']['nodes'][0]['key'], $events))->toBe(['c', 'd']);
});

it('replays a channel patch on connect', function () {
    $render = fn () => $this->asgard(asgardBody(['component' => 'app.native.screens.channel', 'regions' => ['body'], 'state' => ['promo' => 42]]))->json('fragments.body.version');
    $held = $render();
    Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');

    $events = allEvents($this->asgardStream(channelStreamBody(['versions' => ['body' => $held]]))->streamedContent());

    expect(array_column($events, 0))->toBe(['patch'])
        ->and($events[0][1]['from'])->toBe($held)
        ->and($events[0][1]['version'])->toBe($render());
});

it('carries on patching after replaying on connect', function () {
    $held = feedVersion($this);
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(item('d'), into: 'items');

    $events = allEvents($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent());

    expect(array_column($events, 0))->toBe(['patch', 'patch'])
        ->and($events[1][1]['from'])->toBe($events[0][1]['version'])
        ->and($events[1][1]['version'])->toBe(feedVersion($this));
});

it('signals on connect when the patches since the held version are not all in the log', function (Closure $between) {
    $held = feedVersion($this);
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');
    $between();
    Asgard::patch(FEED)->prepend(item('d'), into: 'items');

    $events = allEvents($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent());

    expect(array_column($events, 0))->toBe(['signal'])
        ->and($events[0][1]['version'])->toBe(feedVersion($this));
})->with([
    'a plain region push' => [fn () => Asgard::push(FEED, 'feed')],
    'a component push' => [fn () => Asgard::push(FEED)],
    'an expired entry' => [fn () => cache()->forget(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 1))],
]);

it('signals on connect when the device is further behind than the log keeps', function () {
    usePatchLog($this, 2);
    $held = feedVersion($this);

    foreach (['c', 'd', 'e'] as $key) {
        Asgard::patch(FEED)->prepend(item($key), into: 'items');
    }

    expect(array_column(allEvents($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent()), 0))->toBe(['signal']);
});

it('signals on connect for a version the server never gave', function () {
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');

    expect(array_column(allEvents($this->asgardStream(feedStream(['versions' => ['feed' => 'not-a-real-version']]))->streamedContent()), 0))->toBe(['signal']);
});

it('signals on connect when the newest entry is not written yet', function () {
    $held = feedVersion($this);
    cache()->increment(PushVersions::regionKey(FEED, 'feed'));

    expect(array_column(allEvents($this->asgardStream(feedStream(['versions' => ['feed' => $held]]))->streamedContent()), 0))->toBe(['signal']);
});

it('keeps patching after a connect signal, from the signalled version', function () {
    Asgard::patch(FEED)->prepend(item('c'), into: 'items');
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(item('d'), into: 'items');

    $events = allEvents($this->asgardStream(feedStream())->streamedContent());

    expect(array_column($events, 0))->toBe(['signal', 'patch'])
        ->and($events[1][1]['from'])->toBe($events[0][1]['version']);
});

// ── Falling back ─────────────────────────────────────

it('falls back to one signal when a plain push lands between patches', function (Closure $plain) {
    $this->clock->onSleep[1] = function () use ($plain) {
        Asgard::patch(FEED)->prepend(item('c'), into: 'items');
        $plain();
        Asgard::patch(FEED)->prepend(item('d'), into: 'items');
    };
    $this->clock->onSleep[2] = fn () => Asgard::patch(FEED)->prepend(item('e'), into: 'items');

    $events = allEvents(heldFeedStream($this));

    // The signal covers everything up to it, then patching carries on.
    expect(array_column($events, 0))->toBe(['signal', 'patch'])
        ->and($events[1][1]['from'])->toBe($events[0][1]['version'])
        ->and($events[1][1]['version'])->toBe(feedVersion($this))
        ->and($events[1][1]['patch']['nodes'][0]['key'])->toBe('e');
})->with([
    'a region push' => [fn () => Asgard::push(FEED, 'feed')],
    'a component push' => [fn () => Asgard::push(FEED)],
]);

it('falls back when the stream is further behind than the log keeps', function () {
    usePatchLog($this, 2);

    $this->clock->onSleep[1] = function () {
        foreach (['c', 'd', 'e'] as $key) {
            Asgard::patch(FEED)->prepend(item($key), into: 'items');
        }
    };
    $this->clock->onSleep[2] = function () {
        Asgard::patch(FEED)->prepend(item('f'), into: 'items');
        Asgard::patch(FEED)->prepend(item('g'), into: 'items');
    };

    $events = allEvents(heldFeedStream($this));

    expect(array_column($events, 0))->toBe(['signal', 'patch', 'patch'])
        ->and(cache()->get(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 3)))->toBeNull()
        ->and(cache()->get(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 5)))->not->toBeNull();
});

it('always signals with the patch log off', function () {
    usePatchLog($this, 0);
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(item('c'), into: 'items');

    $events = allEvents(heldFeedStream($this));

    expect(array_column($events, 0))->toBe(['signal'])
        ->and(cache()->get(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 1)))->toBeNull();
});

it('falls back when a log entry in the middle is gone', function () {
    $this->clock->onSleep[1] = function () {
        foreach (['c', 'd', 'e'] as $key) {
            Asgard::patch(FEED)->prepend(item($key), into: 'items');
        }
        cache()->forget(PushVersions::logKey(PushVersions::regionKey(FEED, 'feed'), 2));
    };

    expect(array_column(allEvents(heldFeedStream($this)), 0))->toBe(['signal']);
});

it('waits one poll for a newest entry that is not written yet', function () {
    $counter = PushVersions::regionKey(FEED, 'feed');
    $entry = ['patch' => json_encode(['op' => 'remove', 'target' => 'items', 'keys' => ['a']]), 'types' => []];

    // A patch caught between its counter bump and its log write.
    $this->clock->onSleep[1] = fn () => cache()->increment($counter);
    $this->clock->onSleep[2] = fn () => cache()->put(PushVersions::logKey($counter, 1), $entry, 60);

    $events = allEvents(heldFeedStream($this));

    expect(array_column($events, 0))->toBe(['patch'])
        ->and($events[0][1]['version'])->toBe(feedVersion($this));
});

it('signals when a missing newest entry is still missing on the next poll', function () {
    $this->clock->onSleep[1] = fn () => cache()->increment(PushVersions::regionKey(FEED, 'feed'));

    expect(array_column(allEvents(heldFeedStream($this)), 0))->toBe(['signal']);
});

it('waits patch_wait_ms for a newest entry, over several fast polls', function () {
    config()->set('asgard.stream.poll_ms', 100);
    config()->set('asgard.stream.patch_wait_ms', 250);
    $counter = PushVersions::regionKey(FEED, 'feed');
    $entry = ['patch' => json_encode(['op' => 'remove', 'target' => 'items', 'keys' => ['a']]), 'types' => []];

    // The process writing the patch stalls for three polls between the bump and the entry.
    $this->clock->onSleep[1] = fn () => cache()->increment($counter);
    $this->clock->onSleep[4] = fn () => cache()->put(PushVersions::logKey($counter, 1), $entry, 60);

    $events = allEvents(heldFeedStream($this));

    expect(array_column($events, 0))->toBe(['patch'])
        ->and($events[0][1]['version'])->toBe(feedVersion($this));
});

it('signals once a missing newest entry has been waited for patch_wait_ms', function () {
    config()->set('asgard.stream.poll_ms', 100);
    config()->set('asgard.stream.patch_wait_ms', 250);
    $counter = PushVersions::regionKey(FEED, 'feed');
    $entry = ['patch' => json_encode(['op' => 'remove', 'target' => 'items', 'keys' => ['a']]), 'types' => []];

    $this->clock->onSleep[1] = fn () => cache()->increment($counter);
    $this->clock->onSleep[5] = fn () => cache()->put(PushVersions::logKey($counter, 1), $entry, 60);

    expect(array_column(allEvents(heldFeedStream($this)), 0))->toBe(['signal']);
});

it('signals a client that did not advertise an element type in the patch', function () {
    $node = Node::make('list_item')->key('c')->prop('headline', 'C');

    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend($node, into: 'items');
    expect(array_column(allEvents(heldFeedStream($this)), 0))->toBe(['signal']);

    $this->handshake(['list_item']);
    $this->clock->sleeps = 0;
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend($node, into: 'items');

    $patches = patchData(heldFeedStream($this));
    expect($patches)->toHaveCount(1)
        ->and($patches[0]['patch']['nodes'][0]['type'])->toBe('list_item');
});

it('sends a shared region its fragment when patches cannot cover the step', function () {
    $this->clock->onSleep[1] = function () {
        Asgard::patch(RowsScreen::class, 'rows')->prepend(item('x'), into: 'r1');
        Asgard::push(RowsScreen::class, 'rows');
    };
    $this->clock->onSleep[2] = fn () => Asgard::patch(RowsScreen::class, 'rows')->prepend(item('y'), into: 'r1');

    $events = allEvents($this->asgardStream(streamBody(['component' => 'app.native.screens.rows', 'regions' => ['rows'], 'state' => ['count' => 2]]))->streamedContent());

    expect(array_column($events, 0))->toBe(['fragment', 'fragment', 'patch'])
        ->and($events[2][1]['from'])->toBe($events[1][1]['version']);
});

// ── Channels ─────────────────────────────────────────

it('sends a channel patch only to subscriptions in that channel', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');

    $patches = patchData($this->asgardStream(streamBody([], [
        subscription(['id' => 'p42', 'component' => 'app.native.screens.channel', 'regions' => ['title', 'body'], 'state' => ['promo' => 42]]),
        subscription(['id' => 'p7', 'component' => 'app.native.screens.channel', 'regions' => ['title', 'body'], 'state' => ['promo' => 7]]),
    ]))->streamedContent());

    expect($patches)->toHaveCount(1)
        ->and($patches[0]['subscription'])->toBe('p42')
        ->and($patches[0]['region'])->toBe('body')
        ->and($patches[0]['patch']['target'])->toBe('list');
});

it('chains a channel patch to the version render gives in that channel', function () {
    $render = fn () => $this->asgard(asgardBody(['component' => 'app.native.screens.channel', 'regions' => ['body'], 'state' => ['promo' => 42]]))->json('fragments.body.version');
    $held = $render();
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');

    $patches = patchData($this->asgardStream(channelStreamBody(['versions' => ['body' => $held]]))->streamedContent());

    expect($patches)->toHaveCount(1)
        ->and($patches[0]['from'])->toBe($held)
        ->and($patches[0]['version'])->toBe($render());
});

it('falls back when a patch to everyone and a channel patch land in one poll', function () {
    $this->clock->onSleep[1] = function () {
        Asgard::patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');
        Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('y'), into: 'list');
    };
    $this->clock->onSleep[2] = fn () => Asgard::patch(ChannelScreen::class, 'body')->append(item('z'), into: 'list');

    $events = array_values(array_filter(allEvents($this->asgardStream(channelStreamBody())->streamedContent()), fn ($e) => $e[1]['region'] === 'body'));

    // Connect, the fall back, then patching again.
    expect(array_column($events, 0))->toBe(['signal', 'signal', 'patch']);
});

it('falls back when a plain channel push lands between channel patches', function (Closure $plain) {
    $this->clock->onSleep[1] = function () use ($plain) {
        Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');
        $plain();
        Asgard::to('promo.42')->patch(ChannelScreen::class, 'body')->append(item('y'), into: 'list');
    };

    $events = allEvents($this->asgardStream(channelStreamBody())->streamedContent());

    expect(array_column($events, 0))->toBe(['signal', 'signal']);
})->with([
    'the region in the channel' => [fn () => Asgard::to('promo.42')->push(ChannelScreen::class, 'body')],
    'the component in the channel' => [fn () => Asgard::to('promo.42')->push(ChannelScreen::class)],
    'the whole channel' => [fn () => Asgard::to('promo.42')->push()],
]);

function channelStreamBody(array $overrides = []): array
{
    return streamBody(array_merge(['component' => 'app.native.screens.channel', 'regions' => ['body'], 'state' => ['promo' => 42]], $overrides));
}

// ── Sealing and compression ──────────────────────────

/** Raw id, event and data lines of each event. */
function rawPatchEvents(string $content): array
{
    $events = [];
    foreach (explode("\n\n", $content) as $block) {
        if (preg_match('/^id: (.*)\nevent: (patch)\ndata: (.*)$/', $block, $m)) {
            $events[] = ['id' => $m[1], 'event' => $m[2], 'data' => json_decode($m[3], true)];
        }
    }

    return $events;
}

function manyItems(): array
{
    return array_map(fn (int $i) => Node::row(Node::text("Row {$i}"), Node::text("Detail for row {$i}"))->key("n{$i}")->class('p-2 gap-2'), range(1, 100));
}

it('compresses a patch when its region compresses, and seals it like a fragment', function () {
    $this->clock->onSleep[1] = function () {
        Asgard::patch(RowsScreen::class, 'rows')->prepend(manyItems(), into: 'r1');
        Asgard::patch(RowsScreen::class, 'private')->prepend(manyItems(), into: 'r1');
        Asgard::patch(RowsScreen::class, 'rows')->remove(into: 'r1', keys: ['n1']);
    };

    $content = $this->asgardStream(streamBody(['component' => 'app.native.screens.rows', 'regions' => ['rows', 'private'], 'state' => ['count' => 2]]))->streamedContent();

    $raw = rawPatchEvents($content);
    expect(array_column(array_column($raw, 'data'), 'enc'))->toBe(['gzip', 'identity', 'identity']);

    // A small patch is below the gzip minimum, as a small fragment is.
    $patches = patchData($content);
    expect(array_column($patches, 'region'))->toBe(['rows', 'rows', 'private'])
        ->and($patches[0]['patch']['nodes'])->toHaveCount(100)
        ->and($patches[1]['patch']['op'])->toBe('remove');

    // Signed with `patch` as the event name: the same body under another name does not verify.
    $stream = TestCase::$stream;
    $data = json_encode($raw[0]['data']);
    expect(fn () => Wire::openEvent($stream['keys'], $stream['id'], $stream['signature'], $raw[0]['id'], 'fragment', $data))
        ->toThrow(SecurityException::class);
});

it('keeps the patch event body out of the plaintext stream', function () {
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(Node::text('a secret item')->key('s'), into: 'items');

    $content = heldFeedStream($this);

    expect($content)->toContain("event: patch\n")
        ->and($content)->not->toContain('secret');
});

// ── Callbacks, media and Blade ───────────────────────

it('carries callbacks in patch nodes that run like a rendered node\'s', function () {
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(
        Node::row(Node::text('Five'), Node::button('Like')->onPress('like', 5))->key('n5'),
        into: 'items',
    );

    $patch = patchData(heldFeedStream($this))[0]['patch'];
    $slot = $patch['nodes'][0]['children'][1]['on_press'];

    expect($slot)->toBe(['call' => 'like', 'args' => [5]]);

    $this->asgard(asgardBody(['component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'ios'], 'action' => $slot]))->assertOk();

    expect(FeedScreen::$liked)->toBe([5]);
});

it('takes a Blade region view as the nodes', function () {
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(view('patches.item', ['id' => 7, 'label' => 'Seven']), into: 'items');

    $node = patchData(heldFeedStream($this))[0]['patch']['nodes'][0];

    expect($node['type'])->toBe('row')
        ->and($node['key'])->toBe(7)
        ->and($node['children'][0]['props']['text'])->toBe('Seven')
        ->and($node['children'][1]['on_press'])->toBe(['call' => 'like', 'args' => [7]]);

    $this->asgard(asgardBody(['component' => FEED, 'regions' => ['feed'], 'state' => ['platform' => 'ios'], 'action' => $node['children'][1]['on_press']]))->assertOk();
    expect(FeedScreen::$liked)->toBe([7]);
});

it('makes media URLs absolute in patch nodes', function () {
    config()->set('asgard.media_url', 'https://cdn.example.com');
    $this->clock->onSleep[1] = fn () => Asgard::patch(FEED)->prepend(Node::make('image')->key('i')->prop('src', 'img/x.png'), into: 'items');

    expect(patchData(heldFeedStream($this))[0]['patch']['nodes'][0]['props']['src'])->toBe('https://cdn.example.com/img/x.png');
});

it('checks patch nodes like fragment nodes, at push time', function (Closure $patch, string $message) {
    expect(fn () => $patch(Asgard::patch(FEED)))->toThrow(InvalidFragment::class, $message)
        ->and(cache()->get(PushVersions::regionKey(FEED, 'feed')))->toBeNull();
})->with([
    'a region inside' => [fn ($p) => $p->prepend(Node::make('server_region'), into: 'items'), 'not allowed in a fragment'],
    'screen chrome' => [fn ($p) => $p->append(Node::make('top_bar'), into: 'items'), 'not allowed in a fragment'],
    'shared keys' => [fn ($p) => $p->prepend([item('c'), item('c')], into: 'items'), 'share the key [c]'],
    'a data URL' => [fn ($p) => $p->prepend(Node::make('image')->prop('src', 'data:image/png;base64,AA'), into: 'items'), 'data: URLs'],
    'a bad callback' => [fn ($p) => $p->prepend(['type' => 'button', 'on_press' => ['call' => 'no such!']], into: 'items'), 'on_press'],
    'a local web view' => [fn ($p) => $p->prepend(Node::make('webview')->prop('local', true), into: 'items'), 'webview'],
]);

it('refuses bad patch arguments at push time', function (Closure $patch, string $message) {
    expect(fn () => $patch(Asgard::patch(FEED)))->toThrow(InvalidArgumentException::class, $message)
        ->and(cache()->get(PushVersions::regionKey(FEED, 'feed')))->toBeNull();
})->with([
    'no nodes' => [fn ($p) => $p->prepend([], into: 'items'), 'between 1 and 100 nodes'],
    '101 nodes' => [fn ($p) => $p->append(array_map(fn ($i) => item("k{$i}"), range(1, 101)), into: 'items'), 'between 1 and 100 nodes'],
    'keep 0' => [fn ($p) => $p->prepend(item('c'), into: 'items', keep: 0), 'keep must be between 1 and 100000'],
    'keep too big' => [fn ($p) => $p->prepend(item('c'), into: 'items', keep: 100001), 'keep must be between 1 and 100000'],
    'an empty target' => [fn ($p) => $p->prepend(item('c'), into: ''), 'needs the key'],
    'no keys' => [fn ($p) => $p->remove(into: 'items', keys: []), 'between 1 and 1000 keys'],
    '1001 keys' => [fn ($p) => $p->remove(into: 'items', keys: range(1, 1001)), 'between 1 and 1000 keys'],
    'a bad key' => [fn ($p) => $p->remove(into: 'items', keys: [1.5]), 'strings or ints'],
]);

it('allows the largest patches', function () {
    Asgard::patch(FEED)
        ->append(array_map(fn ($i) => item("k{$i}"), range(1, PendingPatch::MAX_NODES)), into: 'items', keep: PendingPatch::MAX_KEEP)
        ->remove(into: 'items', keys: range(1, PendingPatch::MAX_KEYS));

    expect(cache()->get(PushVersions::regionKey(FEED, 'feed')))->toBe(2);
});

// ── Targets ──────────────────────────────────────────

it('finds the region of a one-region component or a server screen', function () {
    Asgard::patch(FeedScreen::class)->prepend(item('c'), into: 'items');
    Asgard::patch(PromoScreen::class)->prepend(item('c'), into: 'title');

    $promo = array_search(PromoScreen::class, Asgard::all(), true);

    expect(cache()->get(PushVersions::regionKey(FEED, 'feed')))->toBe(1)
        ->and(cache()->get(PushVersions::regionKey($promo, 'screen')))->toBe(1);
});

it('patches a class at every address it is registered under', function () {
    Asgard::patch(ChannelScreen::class, 'body')->append(item('x'), into: 'list');
    Asgard::to('promo.1')->patch(ChannelScreen::class, 'body')->append(item('y'), into: 'list');

    foreach (['app.native.screens.channel', 'app.native.screens.channel_two'] as $address) {
        expect(cache()->get(PushVersions::regionKey($address, 'body')))->toBe(1)
            ->and(cache()->get(PushVersions::channelRegionKey('promo.1', $address, 'body')))->toBe(1);
    }
});

it('refuses a patch it cannot place', function (Closure $patch, string $message) {
    expect($patch)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no region on a component with several' => [fn () => Asgard::patch('app.native.screens.home'), 'needs a region'],
    'an unknown region' => [fn () => Asgard::patch(FEED, 'nope'), 'is not a #[Region]'],
    'an unknown component' => [fn () => Asgard::patch('app.native.screens.nope'), 'is not a registered'],
    'a channel patch to an unknown region' => [fn () => Asgard::to('promo.1')->patch(ChannelScreen::class, 'nope'), 'is not a #[Region]'],
]);
