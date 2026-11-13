<?php

use Native\Asgard\Security\Gzip;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\TestCase;

function rowsBody(string $region, int $count = 200, array $extra = []): array
{
    return ['protocol' => 1, 'component' => 'app.native.screens.rows', 'region' => $region, 'state' => ['count' => $count], 'platform' => 'ios', ...$extra];
}

/** Raw `data` lines of the fragment events in an SSE body, by region order. */
function rawEvents(string $content): array
{
    $events = [];
    foreach (explode("\n\n", $content) as $block) {
        if (preg_match('/^id: (.*)\nevent: (.*)\ndata: (.*)$/', $block, $m)) {
            $events[] = ['id' => $m[1], 'event' => $m[2], 'data' => $m[3]];
        }
    }

    return $events;
}

it('gzips a large fragment before encrypting it', function () {
    $response = $this->asgard(rowsBody('rows'))->assertOk();
    $plaintext = $response->getContent();

    expect($response->headers->get('Content-Encoding'))->toBe('aes128gcm')
        ->and($response->headers->get('Asgard-Content-Coding'))->toBe('gzip')
        ->and($response->json('fragment'))->toHaveCount(200)
        ->and($response->json('fragment.199.children.0.props.text'))->toBe('Row 200')
        ->and($this->wireLength)->toBeLessThan(intdiv(strlen($plaintext), 5));
});

it('leaves a region that opted out uncompressed', function () {
    $response = $this->asgard(rowsBody('private'))->assertOk();

    expect($response->headers->get('Asgard-Content-Coding'))->toBe('identity')
        ->and($response->json('fragment'))->toHaveCount(200)
        ->and($this->wireLength)->toBeGreaterThan(strlen($response->getContent()));
});

it('does not compress a fragment below the minimum size', function () {
    $response = $this->asgard(rowsBody('rows', 2))->assertOk();

    expect(strlen($response->getContent()))->toBeLessThan(Gzip::MIN_SIZE)
        ->and($response->headers->get('Asgard-Content-Coding'))->toBe('identity');
});

it('compresses an action answer the way its region says', function () {
    $this->asgard(rowsBody('rows', 200, ['action' => ['call' => 'more', 'args' => []]]))
        ->assertOk()->assertJsonCount(210, 'fragment')
        ->assertHeader('Asgard-Content-Coding', 'gzip');

    $this->asgard(rowsBody('private', 200, ['action' => ['call' => 'more', 'args' => []]]))
        ->assertOk()->assertJsonCount(210, 'fragment')
        ->assertHeader('Asgard-Content-Coding', 'identity');
});

it('never compresses an error', function () {
    $this->asgard(rowsBody('nope'))->assertNotFound()->assertHeader('Asgard-Content-Coding', 'identity');
});

it('marks each stream event with its coding, per region', function () {
    $this->app->instance(StreamClock::class, new FakeClock);
    config()->set('asgard.stream.lifetime', 1);

    $content = $this->asgardStream(streamBody(['component' => 'app.native.screens.rows', 'regions' => ['rows', 'private'], 'state' => []]))
        ->assertOk()->streamedContent();
    [$rows, $private] = rawEvents($content);
    $stream = TestCase::$stream;

    expect(json_decode($rows['data'], true)['enc'])->toBe('gzip')
        ->and(json_decode($private['data'], true)['enc'])->toBe('identity')
        ->and(strlen($rows['data']))->toBeLessThan(intdiv(strlen($private['data']), 5));

    foreach ([$rows, $private] as $event) {
        $plaintext = Wire::openEvent($stream['keys'], $stream['id'], $stream['signature'], $event['id'], $event['event'], $event['data']);
        expect(json_decode($plaintext, true)['fragment'])->toHaveCount(200);
    }
});

it('refuses an event whose coding marker was flipped or stripped', function () {
    $keys = SessionKeys::fromSecret(random_bytes(32));
    $sig = random_bytes(32);
    $big = str_repeat('{"type":"text","props":{"text":"x"}},', 100);

    $gzip = json_decode(Wire::sealEvent($keys, 's', $sig, '1', 'fragment', $big, null, true), true);
    $plain = json_decode(Wire::sealEvent($keys, 's', $sig, '1', 'fragment', $big, null, false), true);

    $bad = [
        'flipped to identity' => ['body' => $gzip['body'], 'enc' => 'identity', 'sig' => $gzip['sig']],
        'flipped to gzip' => ['body' => $plain['body'], 'enc' => 'gzip', 'sig' => $plain['sig']],
        'stripped' => ['body' => $gzip['body'], 'sig' => $gzip['sig']],
        'unknown coding' => ['body' => $gzip['body'], 'enc' => 'br', 'sig' => $gzip['sig']],
    ];

    foreach ($bad as $data) {
        expect(fn () => Wire::openEvent($keys, 's', $sig, '1', 'fragment', json_encode($data)))->toThrow(SecurityException::class);
    }

    expect(Wire::openEvent($keys, 's', $sig, '1', 'fragment', json_encode($gzip)))->toBe($big);
});

it('refuses a response whose coding was changed after signing, or that it does not know', function () {
    $keys = SessionKeys::fromSecret(random_bytes(32));
    $sig = random_bytes(32);
    $sealed = Wire::sealResponse($keys, 's', 200, str_repeat('a', 5000), $sig, time(), null, true);

    expect($sealed['headers']['asgard-content-coding'])->toBe('gzip');

    $changes = [
        'coding flipped' => ['asgard-content-coding' => 'identity'],
        'coding unknown' => ['asgard-content-coding' => 'br'],
        'stacked content coding' => ['content-encoding' => 'gzip, aes128gcm'],
    ];

    foreach ($changes as $change) {
        $headers = $change + $sealed['headers'];
        expect(fn () => Wire::openResponse($keys, 's', 200, $headers, $sealed['body'], $sig, time()))->toThrow(SecurityException::class);
    }

    $stripped = $sealed['headers'];
    unset($stripped['asgard-content-coding']);
    expect(fn () => Wire::openResponse($keys, 's', 200, $stripped, $sealed['body'], $sig, time()))->toThrow(SecurityException::class);

    expect(Wire::openResponse($keys, 's', 200, $sealed['headers'], $sealed['body'], $sig, time()))->toBe(str_repeat('a', 5000));
});

it('refuses a body that inflates past the cap', function () {
    $keys = SessionKeys::fromSecret(random_bytes(32));
    $sig = random_bytes(32);
    $bomb = str_repeat("\0", Gzip::MAX_INFLATED + 1);
    $sealed = Wire::sealResponse($keys, 's', 200, $bomb, $sig, time(), null, true);

    expect(strlen($sealed['body']))->toBeLessThan(20_000);
    expect(fn () => Wire::openResponse($keys, 's', 200, $sealed['headers'], $sealed['body'], $sig, time()))
        ->toThrow(SecurityException::class, 'inflates past');

    $event = Wire::sealEvent($keys, 's', $sig, '1', 'fragment', $bomb, null, true);
    expect(fn () => Wire::openEvent($keys, 's', $sig, '1', 'fragment', $event))->toThrow(SecurityException::class, 'inflates past');

    expect(strlen(Gzip::decode(Gzip::encode(str_repeat("\0", Gzip::MAX_INFLATED)))))->toBe(Gzip::MAX_INFLATED);
});

it('refuses gzip that is corrupt or cut short', function () {
    $gz = Gzip::encode(str_repeat('hello ', 1000));

    expect(fn () => Gzip::decode(substr($gz, 0, -8)))->toThrow(SecurityException::class)
        ->and(fn () => Gzip::decode('not gzip at all'))->toThrow(SecurityException::class)
        ->and(gzdecode($gz))->toBe(str_repeat('hello ', 1000));
});
