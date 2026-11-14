<?php

use Native\Asgard\Http\Middleware\LimitUploadSize;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\UploadScreen;

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function png(): string
{
    return base64_decode(PNG);
}

function uploadHeader(array $overrides = [], string $bytes = ''): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.uploads',
        'method' => 'savePhoto',
        'args' => [],
        'state' => ['caption' => '', 'count' => 0],
        'filename' => 'IMG_0001.png',
        'mime' => 'image/png',
        'size' => strlen($bytes),
    ], $overrides);
}

/** The frame a device sends: header length, JSON header, bytes. */
function uploadFrame(array $overrides = [], ?string $bytes = null): string
{
    $bytes ??= png();
    $header = json_encode(uploadHeader($overrides, $bytes));

    return pack('N', strlen($header)).$header.$bytes;
}

function leftovers(): array
{
    return glob(sys_get_temp_dir().'/asgard-upload-*') ?: [];
}

it('hands the file to an #[Upload] method and answers with the delta', function () {
    $this->asgard(uploadFrame(['args' => ['Sunset']]), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertExactJson([
            'protocol' => 1,
            'delta' => ['photo' => 'IMG_0001.png', 'caption' => 'Sunset'],
        ]);

    $call = UploadScreen::$calls[0];

    expect($call['method'])->toBe('savePhoto')
        ->and($call['exists'])->toBeTrue()
        ->and($call['valid'])->toBeTrue()
        ->and($call['mime'])->toBe('image/png')
        ->and($call['size'])->toBe(strlen(png()))
        ->and($call['bytes'])->toBe(png());
});

it('ignores a platform member from an older client', function () {
    $this->asgard(uploadFrame(['platform' => 'web']), 'QUERY', [], 'upload')->assertOk();
});

it('runs over POST with the method header', function () {
    $this->asgard(uploadFrame(), 'POST', ['X-Asgard-Method' => 'QUERY'], 'upload')
        ->assertOk()
        ->assertJsonPath('delta.photo', 'IMG_0001.png');
});

it('deletes the temp file once the method has run', function () {
    $before = leftovers();

    $this->asgard(uploadFrame(), 'QUERY', [], 'upload')->assertOk();

    expect(is_file(UploadScreen::$calls[0]['path']))->toBeFalse()
        ->and(leftovers())->toBe($before);
});

it('copes with a method that moved the file itself', function () {
    $this->asgard(uploadFrame(['method' => 'moveIt']), 'QUERY', [], 'upload')->assertOk();

    $moved = sys_get_temp_dir().'/asgard-moved-'.getmypid();
    expect(is_file($moved))->toBeTrue();
    @unlink($moved);
});

it('deletes the temp file when the upload is refused after it was written', function () {
    $before = leftovers();

    $this->asgard(uploadFrame(['method' => 'savePhoto'], "just some text\n"), 'QUERY', [], 'upload')
        ->assertStatus(415);

    $this->asgard(uploadFrame(['state' => ['count' => 'many']]), 'QUERY', [], 'upload')
        ->assertStatus(422);

    expect(leftovers())->toBe($before);
});

it('never uses the device filename as a path', function (string $name, string $shown) {
    $this->asgard(uploadFrame(['filename' => $name]), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertJsonPath('delta.photo', $shown);

    $path = UploadScreen::$calls[0]['path'];

    expect(realpath(dirname($path)))->toBe(realpath(sys_get_temp_dir()))
        ->and(basename($path))->toStartWith('asgard-upload-')
        ->and(UploadScreen::$calls[0]['name'])->toBe($shown);
})->with([
    'traversal' => ['../../../etc/passwd', 'passwd'],
    'windows traversal' => ['..\\..\\boot.ini', 'boot.ini'],
    'absolute' => ['/var/www/.env', '.env'],
    'control characters' => ["evil\x00name\n.png", 'evilname.png'],
    'dots only' => ['..', 'upload'],
    'empty' => ['', 'upload'],
]);

it('sniffs the type from the bytes, whatever the device claims', function () {
    $this->asgard(uploadFrame(['mime' => 'text/plain', 'filename' => 'notes.txt']), 'QUERY', [], 'upload')
        ->assertOk();

    expect(UploadScreen::$calls[0]['mime'])->toBe('image/png')
        ->and(UploadScreen::$calls[0]['client_mime'])->toBe('image/png');
});

it('refuses content whose real type is not allowed, whatever the device claims', function (string $bytes) {
    $this->asgard(uploadFrame(['mime' => 'image/png', 'filename' => 'photo.png'], $bytes), 'QUERY', [], 'upload')
        ->assertStatus(415)
        ->assertJsonPath('error', 'unsupported_type');

    expect(UploadScreen::$calls)->toBe([]);
})->with([
    'text' => ["<?php echo 'hi';\n"],
    'gif' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;"],
]);

it('takes any type when the attribute names none, and a wildcard', function () {
    $this->asgard(uploadFrame(['method' => 'anyFile'], "plain text\n"), 'QUERY', [], 'upload')->assertOk();
    $this->asgard(uploadFrame(['method' => 'anyImage'], "GIF89a\x01\x00\x01\x00\x00\x00\x00;"), 'QUERY', [], 'upload')->assertOk();
    $this->asgard(uploadFrame(['method' => 'anyImage'], "plain text\n"), 'QUERY', [], 'upload')->assertStatus(415);

    expect(array_column(UploadScreen::$calls, 'method'))->toBe(['anyFile', 'anyImage']);
});

it('refuses a file over the method\'s maxSize', function () {
    $this->asgard(uploadFrame([], png().str_repeat("\0", 1024)), 'QUERY', [], 'upload')
        ->assertStatus(413)
        ->assertJsonPath('error', 'too_large');

    expect(UploadScreen::$calls)->toBe([]);
});

it('refuses a file over the config cap, even where the method allows more', function () {
    config()->set('asgard.uploads.max_size', 10);

    $this->asgard(uploadFrame(['method' => 'anyFile'], str_repeat('a', 11)), 'QUERY', [], 'upload')
        ->assertStatus(413)
        ->assertJsonPath('error', 'too_large');

    expect(UploadScreen::$calls)->toBe([]);
});

it('refuses a body too big for the cap before it is decrypted', function () {
    config()->set('asgard.uploads.max_size', 1024);
    $session = $this->handshake();

    // Garbage, not even sealed: the size alone is enough to say no.
    $this->call('QUERY', '/_asgard/upload', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ASGARD_SESSION_ID' => $session['id'],
    ], str_repeat('x', LimitUploadSize::limit(1024) + 1))
        ->assertStatus(413)
        ->assertExactJson(['error' => 'too_large', 'message' => 'The upload is larger than this server takes.']);
});

it('refuses a Content-Length over the cap before reading anything', function () {
    config()->set('asgard.uploads.max_size', 1024);

    $this->call('QUERY', '/_asgard/upload', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) (LimitUploadSize::limit(1024) + 1),
    ], 'x')->assertStatus(413);
});

it('leaves room for a whole file at the cap', function () {
    config()->set('asgard.uploads.max_size', 4096);

    $this->asgard(uploadFrame(['method' => 'anyFile'], random_bytes(4096)), 'QUERY', [], 'upload')
        ->assertOk();
});

it('paints regions and hints refresh() asked for, like a call', function () {
    $response = $this->asgard(uploadFrame(['method' => 'withRefresh']), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertJsonPath('delta.count', 1)
        ->assertJsonPath('fragments.gallery.fragment.props.text', 'Photos: 1')
        ->assertJsonPath('refresh', [['component' => 'app.native.screens.home', 'region' => 'greeting']]);

    expect($response->json('fragments.gallery.version'))->toBeString();
});

it('only runs #[Upload] methods', function (string $method, array $args = []) {
    $this->asgard(uploadFrame(['method' => $method, 'args' => $args]), 'QUERY', [], 'upload')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);

    expect(UploadScreen::$calls)->toBe([]);
})->with([
    'a #[Server] method' => ['serverOnly'],
    'an #[Action]' => ['actionOnly'],
    'unmarked' => ['unmarked'],
    'no file parameter' => ['noFile', ['x']],
    'a nullable file' => ['nullableFile'],
    'file not first' => ['stringFirst', ['x']],
    'protected' => ['hidden'],
    'a region' => ['gallery'],
    'missing' => ['nope'],
    'too few args' => ['takesTwo', [1]],
    'too many args' => ['savePhoto', ['a', 'b']],
]);

it('keeps #[Upload] out of the #[Server] and #[Action] allowlists', function () {
    $metadata = ComponentMetadata::for(UploadScreen::class);

    expect($metadata->servers)->toHaveKeys(['serverOnly'])->not->toHaveKey('savePhoto')
        ->and($metadata->actions)->not->toHaveKey('savePhoto')
        ->and(array_keys($metadata->uploads))->toBe(['savePhoto', 'anyFile', 'anyImage', 'withRefresh', 'moveIt', 'takesTwo'])
        ->and($metadata->uploads['savePhoto']['maxSize'])->toBe(1024)
        ->and($metadata->uploads['savePhoto']['mimes'])->toBe(['image/png', 'image/jpeg']);

    $this->asgard(['protocol' => 1, 'component' => 'app.native.screens.uploads', 'method' => 'savePhoto', 'args' => [], 'state' => []], 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);
});

it('refuses an arg that does not fit its param', function () {
    $this->asgard(uploadFrame(['args' => [42]]), 'QUERY', [], 'upload')
        ->assertStatus(422)
        ->assertJsonPath('message', 'args[0] does not fit the parameter type.');

    expect(UploadScreen::$calls)->toBe([]);
});

it('refuses an unknown component', function () {
    $this->asgard(uploadFrame(['component' => 'app.native.screens.nope']), 'QUERY', [], 'upload')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_component']);
});

it('refuses a malformed frame', function (string $body) {
    $this->asgard($body, 'QUERY', [], 'upload')
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');

    expect(UploadScreen::$calls)->toBe([]);
})->with([
    'too short' => ["\x00\x00"],
    'JSON, not a frame' => [json_encode(['protocol' => 1])],
    'length past the end' => [pack('N', 500).'{}'],
    'header too long' => [pack('N', 2 * 1024 * 1024).'{}'],
    'header not JSON' => [pack('N', 3).'{x}'.png()],
    'header a list' => [pack('N', 2).'[]'.png()],
    'size too small' => [(function () {
        $h = json_encode(uploadHeader(['size' => 10], ''));

        return pack('N', strlen($h)).$h.png();
    })()],
    'size too big' => [(function () {
        $h = json_encode(uploadHeader(['size' => 1000], ''));

        return pack('N', strlen($h)).$h.png();
    })()],
    'size a string' => [(function () {
        $h = json_encode(uploadHeader(['size' => '67'], ''));

        return pack('N', strlen($h)).$h.png();
    })()],
    'empty file' => [uploadFrame([], '')],
    'unknown field' => [uploadFrame(['region' => 'x'])],
    'no args' => [(function () {
        $head = uploadHeader([], png());
        unset($head['args']);
        $h = json_encode($head);

        return pack('N', strlen($h)).$h.png();
    })()],
    'bad method name' => [uploadFrame(['method' => '../savePhoto'])],
    'protocol 2' => [uploadFrame(['protocol' => 2])],
    'filename not a string' => [uploadFrame(['filename' => ['a']])],
    'mime not a string' => [uploadFrame(['mime' => 7])],
    'state that does not fit' => [uploadFrame(['state' => ['count' => 'x']])],
]);

it('refuses an upload without a nonce, early and unsigned', function () {
    $response = $this->asgard(uploadFrame(), 'QUERY', [], 'upload', ['nonce' => false])
        ->assertForbidden()
        ->assertJsonPath('error', 'nonce_required');

    expect($response->headers->has('Signature'))->toBeFalse()
        ->and(UploadScreen::$calls)->toBe([]);
});

it('refuses an upload without a nonce before a bad frame gets a 422', function () {
    $this->asgard('xx', 'QUERY', [], 'upload', ['nonce' => false])
        ->assertForbidden()
        ->assertJsonPath('error', 'nonce_required');
});

it('runs an upload once per nonce and refuses the replay', function () {
    $session = $this->handshake();
    $url = parse_url($this->prepareUrlForRequest('/_asgard/upload'));
    $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

    $sealed = Wire::sealRequest(
        $session['keys'], $session['id'], 'QUERY', $authority, '/_asgard/upload',
        uploadFrame(), [], now()->getTimestamp(), Base64Url::encode(random_bytes(16)),
    );

    $this->sendSealed($sealed, 'QUERY', 'upload')->assertOk();
    $this->sendSealed($sealed, 'QUERY', 'upload')->assertForbidden();

    expect(UploadScreen::$calls)->toHaveCount(1);
});

it('refuses an unsealed upload', function () {
    $this->call('QUERY', '/_asgard/upload', [], [], [], ['CONTENT_TYPE' => 'application/json'], uploadFrame())
        ->assertStatus(419);

    expect(UploadScreen::$calls)->toBe([]);
});

it('never compresses the answer', function () {
    $response = $this->asgard(uploadFrame(['method' => 'withRefresh']), 'QUERY', [], 'upload')->assertOk();

    expect($response->headers->get('Asgard-Content-Coding'))->toBe('identity');
});
