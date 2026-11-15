<?php

use Carbon\CarbonImmutable;
use Native\Asgard\Attributes\Region;
use Native\Asgard\ClientCache;
use Native\Asgard\Node;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\CachedPromoScreen;
use Native\Asgard\Tests\Fixtures\CacheScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\PromoScreen;

beforeEach(function () {
    $this->freezeTime();

    // A stream that ends at once, on a clock that never really sleeps.
    config()->set('asgard.stream.lifetime', 1);
    $this->clock = new FakeClock;
    $this->clock->abortAfter = 0;
    $this->app->instance(StreamClock::class, $this->clock);
    $this->now = now()->getTimestampMs();
});

function cacheBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.cache',
        'region' => 'stats',
        'state' => ['count' => 1],
    ], $overrides);
}

/** A batch render of the cache screen. */
function batchBody(array $overrides): array
{
    $body = cacheBody($overrides);
    unset($body['region']);

    return $body;
}

/** A cache entry for a copy made $ago seconds before now. */
function cachedCopy(int|float $ago, int $ttl = 300): array
{
    return ['made_at' => now()->getTimestampMs() - (int) round($ago * 1000), 'ttl' => $ttl];
}

function cacheCall(string $method, array $args, array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.cache',
        'method' => $method,
        'args' => $args,
        'state' => ['count' => 1],
    ], $overrides);
}

function cacheUpload(array $args, mixed $cache): string
{
    $bytes = 'hello';
    $header = json_encode([
        'protocol' => 1,
        'component' => 'app.native.screens.cache',
        'method' => 'attach',
        'args' => $args,
        'state' => ['count' => 1],
        'cache' => $cache === [] ? (object) [] : $cache,
        'filename' => 'a.txt',
        'size' => strlen($bytes),
    ]);

    return pack('N', strlen($header)).$header.$bytes;
}

// ── made_at and max_age ─────────────────────────────

it('stamps a render with made_at and the region max age', function () {
    $response = $this->asgard(cacheBody())->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'region', 'version', 'made_at', 'max_age', 'fragment'])
        ->and($response->json('made_at'))->toBe($this->now)
        ->and($response->json('max_age'))->toBe(60);
});

it('leaves max_age out when the server sets no limit', function () {
    $response = $this->asgard(cacheBody(['region' => 'greeting']))->assertOk();

    expect($response->json('made_at'))->toBe($this->now)
        ->and($response->json())->not->toHaveKey('max_age');
});

it('takes max_age from config, and a region setting beats it', function () {
    config(['asgard.cache.max_age' => '120']);

    expect($this->asgard(cacheBody(['region' => 'greeting']))->json('max_age'))->toBe(120)
        ->and($this->asgard(cacheBody(['region' => 'stats']))->json('max_age'))->toBe(60)
        ->and($this->asgard(cacheBody(['region' => 'never']))->json('max_age'))->toBe(0);
});

it('refuses a bad max_age in config or on a region', function () {
    config(['asgard.cache.max_age' => -5]);

    expect(fn () => ComponentMetadata::for(CacheScreen::class)->maxAge('greeting'))->toThrow(InvalidArgumentException::class);

    $bad = new class extends ServerComponent
    {
        #[Region('x', maxAge: -1)]
        public function x(): Node
        {
            return Node::text('x');
        }
    };

    expect(fn () => ComponentMetadata::for($bad::class))->toThrow(LogicException::class, 'maxAge');
});

it('stamps every fragment of a batch', function () {
    $response = $this->asgard(batchBody(['regions' => ['stats', 'greeting']]))->assertOk();

    expect(array_keys($response->json('fragments.stats')))->toBe(['version', 'made_at', 'max_age', 'fragment'])
        ->and($response->json('fragments.greeting.made_at'))->toBe($this->now)
        ->and($response->json('fragments.greeting'))->not->toHaveKey('max_age');
});

it('stamps a server screen with config max age, or its own', function () {
    config(['asgard.cache.max_age' => 45]);

    $response = $this->asgard(['protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => ['id' => '1']])->assertOk();

    expect(array_keys($response->json()))->toBe(['protocol', 'region', 'version', 'made_at', 'max_age', 'fragment', 'chrome'])
        ->and($response->json('made_at'))->toBe($this->now)
        ->and($response->json('max_age'))->toBe(45)
        ->and(ComponentMetadata::for(CachedPromoScreen::class)->maxAge('screen'))->toBe(90);
});

it('stamps action answers and the regions refresh() adds', function () {
    $response = $this->asgard(cacheBody(['action' => ['call' => 'bump', 'args' => ['greeting']]]))->assertOk();

    expect($response->json('made_at'))->toBe($this->now)
        ->and($response->json('max_age'))->toBe(60)
        ->and(array_keys($response->json('fragments.greeting')))->toBe(['version', 'made_at', 'fragment']);
});

it('stamps refresh() fragments in calls and uploads', function () {
    $call = $this->asgard(cacheCall('serverBump', [false, 'stats']), 'QUERY', [], 'call')->assertOk();
    $upload = $this->asgard(cacheUpload([false, 'stats'], []), 'QUERY', [], 'upload')->assertOk();

    expect($call->json('fragments.stats.made_at'))->toBe($this->now)
        ->and($call->json('fragments.stats.max_age'))->toBe(60)
        ->and($upload->json('fragments.stats.made_at'))->toBe($this->now)
        ->and($upload->json('fragments.stats.max_age'))->toBe(60);
});

it('stamps a shared fragment on the stream once, for every device', function () {
    $body = streamBody(['component' => 'app.native.screens.cache', 'regions' => ['board'], 'state' => ['count' => 3]]);

    $first = sseEvents($this->asgardStream($body)->streamedContent());
    [, $event] = sseBlocks($this->asgardStream($body)->streamedContent());

    expect($event['event'])->toBe('fragment')
        ->and(array_keys($event['data']))->toBe(['protocol', 'subscription', 'region', 'version', 'made_at', 'max_age', 'fragment'])
        ->and($event['data']['made_at'])->toBe($this->now)
        ->and($event['data']['max_age'])->toBe(30);

    // A later stream for the same state and version reuses the render from
    // the push store, made_at included.
    $this->travel(5)->seconds();
    [, $later] = sseBlocks($this->asgardStream($body)->streamedContent());

    expect($later['data']['made_at'])->toBe($this->now)
        ->and($later['data']['version'])->toBe($first[0]['version'])
        ->and(CacheScreen::$calls)->toBe(['board']);
});

// ── Skipping fresh regions ──────────────────────────

it('skips a fresh region without calling its method', function () {
    $response = $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(10)]]))->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"cached":true}')
        ->and($response->headers->has('Asgard-Cache'))->toBeFalse()
        ->and(CacheScreen::$calls)->toBe([]);
});

it('renders a copy older than the region max age', function () {
    $response = $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(61, 300)]]))->assertOk();

    expect($response->json('fragment.props.text'))->toBe('Stats 1')
        ->and(CacheScreen::$calls)->toBe(['stats']);
});

it('renders a copy older than its own ttl', function () {
    $this->asgard(cacheBody(['region' => 'greeting', 'cache' => ['greeting' => cachedCopy(11, 10)]]))->assertOk()->assertJsonPath('fragment.props.text', 'Hello 1');

    expect(CacheScreen::$calls)->toBe(['greeting']);
});

it('renders a copy whose age is exactly the limit', function () {
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(60, 300)]]))->assertOk()->assertJsonPath('region', 'stats');
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(59.999, 300)]]))->assertOk()->assertJsonPath('cached', true);
});

it('never treats a ttl of 0 or a max age of 0 as fresh', function () {
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(0, 0)]]))->assertOk()->assertJsonPath('region', 'stats');
    $this->asgard(cacheBody(['region' => 'never', 'cache' => ['never' => cachedCopy(0, 300)]]))->assertOk()->assertJsonPath('region', 'never');

    expect(CacheScreen::$calls)->toBe(['stats', 'never']);
});

it('allows a minute of clock skew on made_at and no more', function () {
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(-59)]]))->assertOk()->assertJsonPath('cached', true);
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(-60)]]))->assertOk()->assertJsonPath('cached', true);
    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(-61)]]))->assertOk()->assertJsonPath('region', 'stats');

    expect(CacheScreen::$calls)->toBe(['stats']);
});

it('skips a fresh server screen', function () {
    $response = $this->asgard([
        'protocol' => 1, 'component' => 'promos.show', 'region' => 'screen', 'state' => ['id' => '1'],
        'cache' => ['screen' => cachedCopy(5)],
    ])->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"cached":true}')
        ->and(PromoScreen::$calls)->toBe([]);
});

it('mixes cached and rendered regions in a batch', function () {
    $response = $this->asgard(batchBody([
        'regions' => ['stats', 'greeting', 'never'],
        'cache' => ['stats' => cachedCopy(5), 'never' => cachedCopy(5)],
    ]))->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['stats', 'greeting', 'never'])
        ->and($response->json('fragments.stats'))->toBe(['cached' => true])
        ->and($response->json('fragments.greeting.fragment.props.text'))->toBe('Hello 1')
        ->and($response->json('fragments.never.fragment.props.text'))->toBe('never')
        ->and(CacheScreen::$calls)->toBe(['greeting', 'never']);
});

it('ignores region names the component does not have', function () {
    $this->asgard(cacheBody(['cache' => ['nope' => cachedCopy(1), 'stats' => cachedCopy(1)]]))->assertOk()->assertJsonPath('cached', true);
});

it('keeps a fresh region cached through an action that does not force', function () {
    $response = $this->asgard(cacheBody([
        'action' => ['call' => 'bump', 'args' => ['greeting', 'never']],
        'cache' => ['stats' => cachedCopy(5), 'greeting' => cachedCopy(5)],
    ]))->assertOk();

    expect($response->json())->toBe([
        'protocol' => 1,
        'cached' => true,
        'fragments' => [
            'greeting' => ['cached' => true],
            'never' => $response->json('fragments.never'),
        ],
    ])
        ->and($response->json('fragments.never.fragment.props.text'))->toBe('never')
        ->and(CacheScreen::$calls)->toBe(['never']);
});

it('skips refresh() regions that are fresh in calls and uploads', function () {
    $call = $this->asgard(cacheCall('serverBump', [false, 'stats', 'greeting'], ['cache' => ['stats' => cachedCopy(5)]]), 'QUERY', [], 'call')->assertOk();

    expect($call->json('delta'))->toBe(['count' => 2])
        ->and($call->json('fragments.stats'))->toBe(['cached' => true])
        ->and($call->json('fragments.greeting.fragment.props.text'))->toBe('Hello 2')
        ->and($call->headers->has('Asgard-Cache'))->toBeFalse();

    $upload = $this->asgard(cacheUpload([false, 'stats'], ['stats' => cachedCopy(5)]), 'QUERY', [], 'upload')->assertOk();

    expect($upload->json('fragments.stats'))->toBe(['cached' => true])
        ->and(CacheScreen::$calls)->toBe(['greeting']);
});

it('skips a fresh shared region on render too', function () {
    $this->asgard(cacheBody(['region' => 'board', 'cache' => ['board' => cachedCopy(5)]]))->assertOk()->assertJsonPath('cached', true);

    expect(CacheScreen::$calls)->toBe([]);
});

// ── Forcing invalidation ────────────────────────────

it('renders everything and says so when an action forces invalidation', function () {
    $response = $this->asgard(cacheBody([
        'action' => ['call' => 'bumpAndForce', 'args' => ['greeting']],
        'cache' => ['stats' => cachedCopy(5), 'greeting' => cachedCopy(5)],
    ]))->assertOk();

    expect($response->json('fragment.props.text'))->toBe('Stats 2')
        ->and($response->json('fragments.greeting.fragment.props.text'))->toBe('Hello 2')
        ->and($response->headers->get('Asgard-Cache'))->toBe('invalidate')
        ->and(CacheScreen::$calls)->toBe(['stats', 'greeting']);
});

it('forces invalidation from a call and from an upload', function () {
    $call = $this->asgard(cacheCall('serverBump', [true, 'stats'], ['cache' => ['stats' => cachedCopy(5)]]), 'QUERY', [], 'call')->assertOk();
    $upload = $this->asgard(cacheUpload([true, 'stats'], ['stats' => cachedCopy(5)]), 'QUERY', [], 'upload')->assertOk();

    expect($call->json('fragments.stats.fragment.props.text'))->toBe('Stats 2')
        ->and($call->headers->get('Asgard-Cache'))->toBe('invalidate')
        ->and($upload->json('fragments.stats.fragment.props.text'))->toBe('Stats 2')
        ->and($upload->headers->get('Asgard-Cache'))->toBe('invalidate');
});

it('sends the header on a forced answer that renders nothing', function () {
    $this->asgard(cacheCall('serverBump', [true]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertHeader('Asgard-Cache', 'invalidate');
});

it('lets checkClientCache() force invalidation for a stale copy', function () {
    CacheScreen::$changedAt = $this->now - 2000;

    // Made before the change: rendered, with the header.
    $stale = $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(3)]]))->assertOk();

    expect($stale->json('fragment.props.text'))->toBe('Stats 1')
        ->and($stale->headers->get('Asgard-Cache'))->toBe('invalidate');

    // Made after it: still skipped.
    $fresh = $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(1)]]))->assertOk();

    expect($fresh->getContent())->toBe('{"protocol":1,"cached":true}')
        ->and(CacheScreen::$calls)->toBe(['stats']);
});

it('hands checkClientCache() the copy, and only for fresh regions', function () {
    $this->asgard(batchBody([
        'regions' => ['stats', 'greeting'],
        'cache' => ['stats' => cachedCopy(4, 200), 'greeting' => cachedCopy(20, 10)],
    ]))->assertOk();

    $cache = CacheScreen::$checked['stats'];

    expect(array_keys(CacheScreen::$checked))->toBe(['stats'])
        ->and($cache->region)->toBe('stats')
        ->and($cache->ttl)->toBe(200)
        ->and($cache->maxAge)->toBe(60)
        ->and($cache->madeAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($cache->madeAt->getTimestampMs())->toBe($this->now - 4000)
        ->and($cache->age())->toBe(4.0)
        ->and($cache->isFresh())->toBeTrue();
});

it('lets one forced region in a batch bring back the ones it would have skipped', function () {
    CacheScreen::$changedAt = $this->now;

    $response = $this->asgard(batchBody([
        'regions' => ['stats', 'greeting'],
        'cache' => ['stats' => cachedCopy(30), 'greeting' => ['made_at' => $this->now + 1000, 'ttl' => 60]],
    ]))->assertOk();

    // The hook forces on stats; greeting, made "after" the change, renders too.
    expect($response->json('fragments.stats.fragment.props.text'))->toBe('Stats 1')
        ->and($response->json('fragments.greeting.fragment.props.text'))->toBe('Hello 1')
        ->and($response->headers->get('Asgard-Cache'))->toBe('invalidate');
});

it('renders regions already passed over when a region method forces late', function () {
    $response = $this->asgard(batchBody([
        'regions' => ['stats', 'late', 'greeting'],
        'cache' => ['stats' => cachedCopy(5), 'greeting' => cachedCopy(5)],
    ]))->assertOk();

    expect(array_keys($response->json('fragments')))->toBe(['stats', 'late', 'greeting'])
        ->and($response->json('fragments.stats.fragment.props.text'))->toBe('Stats 1')
        ->and($response->json('fragments.greeting.fragment.props.text'))->toBe('Hello 1')
        ->and($response->headers->get('Asgard-Cache'))->toBe('invalidate')
        ->and(CacheScreen::$calls)->toBe(['late', 'stats', 'greeting']);
});

it('signs the Asgard-Cache header', function () {
    $response = $this->asgard(cacheCall('serverBump', [true]), 'QUERY', [], 'call')->assertOk();

    expect($response->headers->get('Signature-Input'))->toContain('"asgard-protocol" "asgard-cache" "signature";req');

    $plain = $this->asgard(cacheBody())->assertOk();

    expect($plain->headers->get('Signature-Input'))->not->toContain('asgard-cache');
});

it('refuses a response whose Asgard-Cache header was added or removed', function () {
    $keys = SessionKeys::fromSecret(random_bytes(32));
    $request = random_bytes(32);
    $now = time();

    $forced = Wire::sealResponse($keys, 'sess', 200, '{}', $request, $now, null, false, 'invalidate');
    $plain = Wire::sealResponse($keys, 'sess', 200, '{}', $request, $now);

    expect(Wire::openResponse($keys, 'sess', 200, $forced['headers'], $forced['body'], $request, $now))->toBe('{}');

    $stripped = $forced['headers'];
    unset($stripped['asgard-cache']);

    expect(fn () => Wire::openResponse($keys, 'sess', 200, $stripped, $forced['body'], $request, $now))->toThrow(SecurityException::class)
        ->and(fn () => Wire::openResponse($keys, 'sess', 200, $plain['headers'] + ['asgard-cache' => 'invalidate'], $plain['body'], $request, $now))->toThrow(SecurityException::class)
        ->and(fn () => Wire::openResponse($keys, 'sess', 200, ['asgard-cache' => 'keep'] + $forced['headers'], $forced['body'], $request, $now))->toThrow(SecurityException::class);
});

// ── clientCache() ───────────────────────────────────

it('gives clientCache() the declared copies and null for the rest', function () {
    $this->asgard(cacheCall('serverBump', [false], ['cache' => ['stats' => cachedCopy(2, 100), 'nope' => cachedCopy(1)]]), 'QUERY', [], 'call')->assertOk();

    expect(CacheScreen::$seen['stats'])->toBeInstanceOf(ClientCache::class)
        ->and(CacheScreen::$seen['stats']->ttl)->toBe(100)
        ->and(CacheScreen::$seen['stats']->age())->toBe(2.0)
        ->and(CacheScreen::$seen['greeting'])->toBeNull()
        ->and(CacheScreen::$seen['nope'])->toBeNull();
});

it('works out freshness by the shorter of ttl and max age', function () {
    $now = now()->getTimestampMs();

    expect((new ClientCache('x', $now - 9_000, 10))->isFresh())->toBeTrue()
        ->and((new ClientCache('x', $now - 10_000, 10))->isFresh())->toBeFalse()
        ->and((new ClientCache('x', $now - 9_000, 10, 5))->isFresh())->toBeFalse()
        ->and((new ClientCache('x', $now - 4_000, 10, 5))->isFresh())->toBeTrue()
        ->and((new ClientCache('x', $now + 60_000, 10))->isFresh())->toBeTrue()
        ->and((new ClientCache('x', $now + 60_001, 10))->isFresh())->toBeFalse()
        ->and((new ClientCache('x', $now + 1_000, 10))->age())->toBe(-1.0);
});

// ── Validation ──────────────────────────────────────

dataset('bad cache members', [
    'a list' => [[['made_at' => 1, 'ttl' => 1]]],
    'a string' => ['stats'],
    'too many entries' => [array_combine(array_map(fn ($i) => "r{$i}", range(1, 65)), array_fill(0, 65, ['made_at' => 1, 'ttl' => 1]))],
    'a bad region name' => [['bad name' => ['made_at' => 1, 'ttl' => 1]]],
    'an entry that is not an object' => [['stats' => 5]],
    'an entry that is a list' => [['stats' => [1, 2]]],
    'no made_at' => [['stats' => ['ttl' => 1]]],
    'no ttl' => [['stats' => ['made_at' => 1]]],
    'made_at 0' => [['stats' => ['made_at' => 0, 'ttl' => 1]]],
    'a negative made_at' => [['stats' => ['made_at' => -5, 'ttl' => 1]]],
    'a float made_at' => [['stats' => ['made_at' => 1.5, 'ttl' => 1]]],
    'a string made_at' => [['stats' => ['made_at' => '1763150000123', 'ttl' => 1]]],
    'a negative ttl' => [['stats' => ['made_at' => 1, 'ttl' => -1]]],
    'a ttl over a year' => [['stats' => ['made_at' => 1, 'ttl' => 31_536_001]]],
    'a float ttl' => [['stats' => ['made_at' => 1, 'ttl' => 1.5]]],
    'an unknown member' => [['stats' => ['made_at' => 1, 'ttl' => 1, 'fingerprint' => 'x']]],
]);

it('refuses a bad cache member on render', function (mixed $cache) {
    $this->asgard(cacheBody(['cache' => $cache]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');

    expect(CacheScreen::$calls)->toBe([]);
})->with('bad cache members');

it('refuses a bad cache member on calls and uploads', function (mixed $cache) {
    $this->asgard(cacheCall('serverBump', [false], ['cache' => $cache]), 'QUERY', [], 'call')->assertStatus(422);
    $this->asgard(cacheUpload([false], $cache), 'QUERY', [], 'upload')->assertStatus(422);
})->with('bad cache members');

it('takes an empty cache member, and a ttl of a year', function () {
    $this->asgard(cacheBody(['cache' => (object) []]))->assertOk()->assertJsonPath('region', 'stats');
    $this->asgard(cacheBody(['cache' => ['greeting' => ['made_at' => 1, 'ttl' => 31_536_000]], 'region' => 'greeting']))->assertOk()->assertJsonPath('region', 'greeting');
    $this->asgard(cacheCall('serverBump', [false], ['cache' => []]), 'QUERY', [], 'call')->assertOk();
});

// ── Fetches caused by a push ────────────────────────

it('tells the component when a render was fetched for a push', function () {
    $this->asgard(cacheBody(['reason' => 'push']))->assertOk();
    expect(CacheScreen::$push)->toBeTrue();

    $this->asgard(cacheBody())->assertOk();
    expect(CacheScreen::$push)->toBeFalse();
});

it('does not force anything for a push by itself', function () {
    $this->asgard(cacheBody(['reason' => 'push', 'cache' => ['stats' => cachedCopy(5)]]))
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'cached' => true]);
});

it('lets checkClientCache() force invalidation for a push fetch', function () {
    CacheScreen::$pushWins = true;

    $pushed = $this->asgard(batchBody(['regions' => ['stats', 'greeting'], 'reason' => 'push', 'cache' => ['stats' => cachedCopy(5), 'greeting' => cachedCopy(5)]]))->assertOk();

    expect($pushed->json('fragments.stats.fragment.props.text'))->toBe('Stats 1')
        ->and($pushed->json('fragments.greeting.fragment.props.text'))->toBe('Hello 1')
        ->and($pushed->headers->get('Asgard-Cache'))->toBe('invalidate');

    $this->asgard(cacheBody(['cache' => ['stats' => cachedCopy(5)]]))->assertOk()->assertJsonPath('cached', true);
});

it('refuses any reason but push', function (mixed $reason) {
    $this->asgard(cacheBody(['reason' => $reason]))->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    $this->asgard(batchBody(['regions' => ['stats'], 'reason' => $reason]))->assertStatus(422);
})->with([
    'another word' => ['refresh'],
    'null' => [null],
    'a bool' => [true],
    'upper case' => ['PUSH'],
]);

it('refuses a reason on calls, which push never causes', function () {
    $this->asgard(cacheCall('serverBump', [false], ['reason' => 'push']), 'QUERY', [], 'call')->assertStatus(422);
});
