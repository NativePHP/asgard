<?php

use Illuminate\Support\Facades\Cache;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\ContentDigest;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\SessionStore;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\TestCase;

function handshakeBody(array $overrides = []): string
{
    [, $public] = Ecdh::generate();

    return json_encode(array_merge([
        'key' => TestCase::KEY, 'protocol' => 1, 'accepts' => ['tree/json'], 'client_public' => Base64Url::encode($public),
    ], $overrides));
}

/** A tamper hook that hands the sealed request to $into and changes nothing. */
function capture(?array &$into): Closure
{
    return function (array $sealed) use (&$into) {
        return $into = $sealed;
    };
}

// Handshake

it('agrees a session without the secret crossing the wire', function () {
    [$private, $public] = Ecdh::generate();

    $response = $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'key' => TestCase::KEY, 'protocol' => 1, 'accepts' => ['tree/json'], 'client_public' => Base64Url::encode($public),
    ]))->assertOk();

    expect(array_keys($response->json()))->toBe(['session', 'server_public', 'expires_in', 'encoding'])
        ->and($response->json('expires_in'))->toBe(1800)
        ->and($response->json('encoding'))->toBe('tree/json')
        ->and(strlen(Base64Url::decode($response->json('server_public'))))->toBe(65);

    // The client derives the same secret the server stored.
    $serverPublic = Base64Url::decode($response->json('server_public'));
    $secret = SessionKeys::secret(Ecdh::agree($private, $serverPublic), $public, $serverPublic);

    expect(Base64Url::decode(Cache::get(SessionStore::PREFIX.$response->json('session'))))->toBe($secret)
        ->and($response->getContent())->not->toContain(Base64Url::encode($secret));
});

it('refuses a handshake with the wrong key, or with no key configured', function () {
    $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], handshakeBody(['key' => 'nope']))
        ->assertForbidden()->assertJsonPath('error', 'unknown_key');

    config()->set('asgard.key', '');

    $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], handshakeBody(['key' => '']))
        ->assertForbidden()->assertJsonPath('error', 'unknown_key');
});

it('refuses a bad handshake', function (array $overrides, string $error) {
    $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], handshakeBody($overrides))
        ->assertStatus(422)->assertJsonPath('error', $error);
})->with([
    'other protocol' => [['protocol' => 2], 'unsupported_protocol'],
    'no tree/json' => [['accepts' => ['tree/flat']], 'unsupported_encoding'],
    'no public key' => [['client_public' => null], 'invalid_request'],
    'not base64url' => [['client_public' => 'a+b/'], 'invalid_request'],
    'compressed point' => [['client_public' => Base64Url::encode("\x02".str_repeat("\x01", 32))], 'invalid_request'],
    'point off the curve' => [['client_public' => Base64Url::encode("\x04".str_repeat("\x01", 64))], 'invalid_request'],
]);

// Requests

it('sends ciphertext both ways and binds the response to its request', function () {
    $response = $this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => capture($sealed)])->assertOk();

    expect($sealed['body'])->not->toContain('Simon')
        ->and($sealed['headers']['content-encoding'])->toBe('aes128gcm')
        ->and($sealed['headers']['signature-input'])->toStartWith('asgard=("@method" "@authority" "@path" "content-type" "content-encoding" "content-digest" "asgard-protocol" "asgard-session-id");created=')
        ->and($response->headers->get('Content-Encoding'))->toBe('aes128gcm')
        ->and($response->headers->get('Signature-Input'))->toContain('"signature";req;key="asgard"')
        ->and($response->json('fragment.children.0.props.text'))->toBe('Hello, Simon');

    // The same raw response does not verify against any other request.
    $raw = $this->sendSealed($sealed);
    $headers = array_map(fn ($v) => implode(', ', $v), $raw->headers->all());

    expect($raw->getContent())->not->toContain('Simon');
    expect(fn () => Wire::openResponse($this->asgardSession['keys'], $this->asgardSession['id'], 200, $headers, $raw->getContent(), str_repeat("\0", 32), time()))
        ->toThrow(SecurityException::class);
});

it('answers 419 for an unknown or missing session, before anything else', function () {
    $this->handshake();

    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => function ($sealed) {
        $sealed['headers']['asgard-session-id'] = 'AAAAAAAAAAAAAAAAAAAAAAAA';

        return $sealed;
    }])->assertStatus(419)->assertJsonPath('error', 'session_expired');

    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => function ($sealed) {
        unset($sealed['headers']['asgard-session-id']);

        return $sealed;
    }])->assertStatus(419);

    expect(HomeScreen::$calls)->toBe([]);
});

it('answers 419 once the session expires on the server', function () {
    $this->handshake();
    Cache::forget(SessionStore::PREFIX.$this->asgardSession['id']);

    $this->asgard(asgardBody())->assertStatus(419);
});

it('refuses a tampered request with 403 and never runs it', function (Closure $tamper) {
    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => $tamper])
        ->assertForbidden()
        ->assertExactJson(['error' => 'forbidden', 'message' => 'The request failed its integrity check.']);

    expect(HomeScreen::$calls)->toBe([]);
})->with([
    'body changed' => [function ($s) {
        $s['body'][40] = chr(ord($s['body'][40]) ^ 1);

        return $s;
    }],
    'body and digest changed' => [function ($s) {
        $s['body'][40] = chr(ord($s['body'][40]) ^ 1);
        $s['headers']['content-digest'] = ContentDigest::header($s['body']);

        return $s;
    }],
    'digest removed' => [function ($s) {
        unset($s['headers']['content-digest']);

        return $s;
    }],
    'signature changed' => [function ($s) {
        $s['headers']['signature'] = 'asgard=:'.base64_encode(str_repeat('x', 32)).':';

        return $s;
    }],
    'signature removed' => [function ($s) {
        unset($s['headers']['signature'], $s['headers']['signature-input']);

        return $s;
    }],
    'covered header changed' => [function ($s) {
        $s['headers']['asgard-protocol'] = '2';

        return $s;
    }],
    'component dropped from the signature' => [function ($s) {
        $s['headers']['signature-input'] = str_replace(' "asgard-session-id"', '', $s['headers']['signature-input']);

        return $s;
    }],
    'not encrypted' => [function ($s) {
        $s['headers']['content-encoding'] = 'identity';

        return $s;
    }],
]);

it('refuses a request whose signature has expired or is from the future', function () {
    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['created' => now()->getTimestamp() - 301])->assertForbidden();
    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['created' => now()->getTimestamp() + 61])->assertForbidden();
    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['created' => now()->getTimestamp() - 299])->assertOk();
});

it('refuses a replayed render once its signature expires', function () {
    $this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => capture($sealed)])->assertOk();

    // A read has no nonce, so within its window a replay is just another read.
    $this->sendSealed($sealed)->assertOk();

    $this->travel(301)->seconds();
    $this->sendSealed($sealed)->assertForbidden();
});

it('signs the method override and the bearer token when they are sent', function () {
    $this->asgard(asgardBody(), 'POST', ['X-Asgard-Method' => 'QUERY', 'Authorization' => 'Bearer abc'], 'render', ['tamper' => capture($sealed)])
        ->assertOk();

    expect($sealed['headers']['signature-input'])->toContain('"asgard-session-id" "x-asgard-method" "authorization");');

    $sealed['headers']['authorization'] = 'Bearer someone-else';
    $this->sendSealed($sealed, 'POST', 'render')->assertForbidden();
});

it('still refuses POST without the method header before looking at the session', function () {
    $this->asgard(asgardBody(), 'POST', [], 'render', ['tamper' => function ($s) {
        unset($s['headers']['asgard-session-id']);

        return $s;
    }])->assertStatus(405);
});

// Server actions

it('runs an action once per nonce and refuses the replay', function () {
    $body = asgardBody(['state' => ['name' => 'Simon', 'count' => 2], 'action' => ['call' => 'shuffle', 'args' => [3, '!']]]);

    $this->asgard($body, 'QUERY', [], 'render', ['tamper' => capture($sealed)])->assertOk();
    expect($sealed['headers']['signature-input'])->toMatch('/;nonce="[A-Za-z0-9_-]{22}"/');

    $this->sendSealed($sealed)->assertForbidden();
    expect(HomeScreen::$calls)->toHaveCount(1);
});

it('refuses an action without a nonce', function () {
    $body = asgardBody(['action' => ['call' => 'shuffle', 'args' => []]]);

    $this->asgard($body, 'QUERY', [], 'render', ['nonce' => false])
        ->assertForbidden()
        ->assertExactJson(['error' => 'nonce_required']);

    expect(HomeScreen::$calls)->toBe([]);
});

it('keeps nonces per session', function () {
    $nonce = Base64Url::encode(random_bytes(16));
    $body = asgardBody(['action' => ['call' => 'shuffle', 'args' => []]]);

    $this->asgard($body, 'QUERY', [], 'render', ['nonce' => $nonce])->assertOk();
    $this->asgard($body, 'QUERY', [], 'render', ['nonce' => $nonce])->assertForbidden();

    $this->handshake();
    $this->asgard($body, 'QUERY', [], 'render', ['nonce' => $nonce])->assertOk();
});

// Streams

it('seals stream events to the request that opened the stream', function () {
    $this->app->instance(StreamClock::class, new FakeClock);
    config()->set('asgard.stream.lifetime', 1);

    $content = $this->asgardStream(streamBody(['regions' => ['greeting']]))->assertOk()->streamedContent();
    $event = sseBlocks($content)[1];
    $raw = explode("\n", explode("\n\n", $content)[1]);
    $data = substr($raw[2], strlen('data: '));
    $stream = TestCase::$stream;

    expect($event['data']['region'])->toBe('greeting');

    // Another stream's signature, another id or another event name: all refused.
    foreach ([[str_repeat("\1", 32), '0', 'fragment'], [$stream['signature'], '1', 'fragment'], [$stream['signature'], '0', 'message']] as [$sig, $id, $name]) {
        expect(fn () => Wire::openEvent($stream['keys'], $stream['id'], $sig, $id, $name, $data))->toThrow(SecurityException::class);
    }
});

it('refuses a replayed stream request once its signature expires', function () {
    $this->app->instance(StreamClock::class, new FakeClock);
    config()->set('asgard.stream.lifetime', 1);

    $this->asgardStream(streamBody(), 'POST', [], ['tamper' => capture($sealed)])->assertOk();

    $this->travel(301)->seconds();
    $this->sendSealed($sealed, 'POST', 'stream')->assertForbidden();
});
