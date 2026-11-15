<?php

use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Support\ConfiguredHeaders;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\ComputedScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\TestCase;

beforeEach(function () {
    config()->set('asgard.headers', [
        'X-Server-Region' => 'eu-west',
        'X-Build' => 42,
        'X-From-Callable' => [ComputedScreen::class, 'headerValue'],
        'X-From-Closure' => fn () => 'closure',
        'X-Left-Out' => [ComputedScreen::class, 'nothingToSay'],
        'X-Also-Left-Out' => null,
    ]);
});

function expectConfiguredHeaders($response): void
{
    expect($response->headers->get('X-Server-Region'))->toBe('eu-west')
        ->and($response->headers->get('X-Build'))->toBe('42')
        ->and($response->headers->get('X-From-Callable'))->toBe('from-callable')
        ->and($response->headers->get('X-From-Closure'))->toBe('closure')
        ->and($response->headers->has('X-Left-Out'))->toBeFalse()
        ->and($response->headers->has('X-Also-Left-Out'))->toBeFalse();
}

it('adds the headers to the handshake', function () {
    [, $public] = Ecdh::generate();

    $response = $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'key' => TestCase::KEY, 'protocols' => [1], 'accepts' => ['tree/json'], 'client_public' => Base64Url::encode($public),
    ]))->assertOk();

    expectConfiguredHeaders($response);
});

it('adds the headers to sealed answers, and the signature still verifies', function () {
    // asgard() fails the test when a signed answer does not verify.
    expectConfiguredHeaders($this->asgard(asgardBody())->assertOk());
    expectConfiguredHeaders($this->asgard(['protocol' => 1], 'POST', ['X-Asgard-Method' => 'QUERY'], 'routes')->assertOk());
    expectConfiguredHeaders($this->asgard([
        'protocol' => 1, 'component' => 'app.native.screens.computed', 'method' => 'nothing', 'args' => [], 'state' => [],
    ], 'QUERY', [], 'call')->assertOk());
});

it('adds the headers to an upload answer', function () {
    $bytes = 'hello';
    $header = json_encode([
        'protocol' => 1, 'component' => 'app.native.screens.computed', 'method' => 'attach', 'args' => [],
        'state' => [], 'filename' => 'a.txt', 'mime' => 'text/plain', 'size' => strlen($bytes),
    ]);

    expectConfiguredHeaders($this->asgard(pack('N', strlen($header)).$header.$bytes, 'QUERY', [], 'upload')->assertOk());
});

it('adds the headers to the stream', function () {
    config()->set('asgard.stream.lifetime', 1);
    $this->app->instance(StreamClock::class, new FakeClock);

    $response = $this->asgardStream(streamBody())->assertOk();

    expectConfiguredHeaders($response);
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
});

it('adds the headers to errors, sealed or plain', function () {
    // Sealed 404 and 422.
    expectConfiguredHeaders($this->asgard(asgardBody(['component' => 'nope']))->assertNotFound());
    expectConfiguredHeaders($this->asgard(asgardBody(['regions' => [5]]))->assertStatus(422));

    // Plain 419, 403 and 405s.
    expectConfiguredHeaders($this->call('QUERY', '/_asgard/render', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(419));
    expectConfiguredHeaders($this->asgard(asgardBody(), 'QUERY', [], 'render', ['tamper' => function ($sealed) {
        $sealed['body'] .= 'x';

        return $sealed;
    }])->assertForbidden());
    expectConfiguredHeaders($this->call('GET', '/_asgard/render')->assertStatus(405));
    expectConfiguredHeaders($this->call('POST', '/_asgard/render', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(405));
    expectConfiguredHeaders($this->call('BREW', '/_asgard/call')->assertStatus(405));
    expectConfiguredHeaders($this->call('GET', '/_asgard/session')->assertStatus(405));

    // A handshake the server refuses.
    expectConfiguredHeaders($this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(403));
});

it('leaves the headers out of the signature', function () {
    $response = $this->asgard(asgardBody())->assertOk();

    expect(strtolower($response->headers->get('Signature-Input')))->not->toContain('x-server-region');
});

it('refuses a header name Asgard sets, signs or a client reads', function (string $name) {
    config()->set('asgard.headers', [$name => 'x']);

    expect(fn () => ConfiguredHeaders::resolve())->toThrow(InvalidArgumentException::class, 'cannot set');
})->with([
    'Content-Type', 'content-encoding', 'Content-Digest', 'Content-Length', 'Signature', 'Signature-Input',
    'Asgard-Protocol', 'Asgard-Content-Coding', 'X-Asgard-Method', 'Cache-Control', 'Set-Cookie',
    'Transfer-Encoding', 'Connection', 'Allow', 'X-Accel-Buffering', 'Keep-Alive', 'Upgrade', 'Trailer',
]);

it('refuses a bad header name or value', function (array $headers, string $message) {
    config()->set('asgard.headers', $headers);

    expect(fn () => ConfiguredHeaders::resolve())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'space in name' => [['X Bad' => 'x'], 'is not a header name'],
    'colon in name' => [['X-Bad:' => 'x'], 'is not a header name'],
    'empty name' => [['' => 'x'], 'is not a header name'],
    'newline' => [['X-Bad' => "a\r\nSet-Cookie: x"], 'line break'],
    'nul' => [['X-Bad' => "a\0b"], 'line break'],
    'array' => [['X-Bad' => ['a', 'b']], 'must be a string'],
    'bool' => [['X-Bad' => true], 'must be a string'],
    'callable giving an array' => [['X-Bad' => fn () => ['a']], 'must be a string'],
]);

it('refuses a headers config that is not an array', function () {
    config()->set('asgard.headers', 'X-Bad: x');

    expect(fn () => ConfiguredHeaders::resolve())->toThrow(InvalidArgumentException::class, 'must be an array');
});

it('takes null as no headers', function () {
    config()->set('asgard.headers', null);

    expect(ConfiguredHeaders::resolve())->toBe([]);
});
