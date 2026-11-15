<?php

use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavAction;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Node;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Tests\Fixtures\BadFragments;

/**
 * Media URLs leave the server absolute (Asgard protocol §9.6). The device reads a
 * relative src as one of the app's own files, and refuses one in a fragment.
 */
function imageSrc(mixed $src, string $type = 'image', string $prop = 'src'): mixed
{
    return FragmentValidator::validate(Node::make($type)->prop($prop, $src), ['list_item', 'webview', 'acme_video'])['props'][$prop] ?? null;
}

it('resolves relative media URLs against the app URL', function (string $src, string $expected) {
    expect(imageSrc($src))->toBe($expected);
})->with([
    'relative' => ['img/x.png', 'http://localhost/img/x.png'],
    'dot relative' => ['./img/x.png', 'http://localhost/img/x.png'],
    'root relative' => ['/storage/promo.jpg', 'http://localhost/storage/promo.jpg'],
    'with a query' => ['/img/x.png?v=2', 'http://localhost/img/x.png?v=2'],
    'with a space' => ['img/my photo.png', 'http://localhost/img/my%20photo.png'],
    'protocol relative' => ['//cdn.test/x.png', 'http://cdn.test/x.png'],
    'https' => ['https://cdn.test/x.png', 'https://cdn.test/x.png'],
    'http' => ['http://cdn.test/x.png', 'http://cdn.test/x.png'],
    'upper case scheme' => ['HTTPS://cdn.test/x.png', 'HTTPS://cdn.test/x.png'],
]);

it('leaves an empty src empty', function () {
    expect(imageSrc(''))->toBe('');
});

it('refuses other schemes', function (string $src) {
    expect(fn () => imageSrc($src))->toThrow(InvalidFragment::class);
})->with([
    'javascript' => 'javascript:alert(1)',
    'file' => 'file:///etc/passwd',
    'content' => 'content://media/external/images/1',
    'data' => 'data:image/png;base64,iVBORw0KGgo=',
    'php' => 'php://127.0.0.1/settings',
]);

it('says why it refuses data: URLs', function () {
    expect(fn () => imageSrc('data:image/png;base64,iVBORw0KGgo='))
        ->toThrow(InvalidFragment::class, 'data: URLs are not allowed');
});

it('refuses a media URL that is not a string', function () {
    expect(fn () => imageSrc(['a']))->toThrow(InvalidFragment::class, 'must be a string');
});

it('refuses control characters', function () {
    expect(fn () => imageSrc("img/x.png\n"))->toThrow(InvalidFragment::class, 'control characters');
});

it('resolves list item avatars and images and any src', function () {
    expect(imageSrc('me.png', 'list_item', 'leadingAvatar'))->toBe('http://localhost/me.png');
    expect(imageSrc('/me.png', 'list_item', 'leadingImage'))->toBe('http://localhost/me.png');
    expect(imageSrc('/', 'webview'))->toBe('http://localhost/');
    expect(imageSrc('clip.mp4', 'acme_video'))->toBe('http://localhost/clip.mp4');
});

it('leaves other props alone', function () {
    expect(imageSrc('img/x.png', 'text', 'text'))->toBe('img/x.png');
});

it('resolves deep in a fragment', function () {
    $fragment = FragmentValidator::validate(Node::column(Node::row(Node::make('image')->prop('src', 'a.png'))));

    expect($fragment['children'][0]['children'][0]['props']['src'])->toBe('http://localhost/a.png');
});

it('follows ASSET_URL', function () {
    url()->useAssetOrigin('https://cdn.example.com/assets');

    try {
        expect(imageSrc('/img/x.png'))->toBe('https://cdn.example.com/assets/img/x.png');
        expect(imageSrc('//other.test/x.png'))->toBe('https://other.test/x.png');
    } finally {
        url()->useAssetOrigin(null);
    }
});

it('uses asgard.media_url over the request', function () {
    config(['asgard.media_url' => 'https://media.example.com/']);

    expect(imageSrc('img/x.png'))->toBe('https://media.example.com/img/x.png');
    expect(imageSrc('/img/x.png'))->toBe('https://media.example.com/img/x.png');
    expect(imageSrc('//cdn.test/x.png'))->toBe('https://cdn.test/x.png');
    expect(imageSrc('https://cdn.test/x.png'))->toBe('https://cdn.test/x.png');
});

it('refuses a web view that loads the app\'s own content', function (string $prop, mixed $value) {
    expect(fn () => FragmentValidator::validate(Node::make('webview')->props(['src' => '/', $prop => $value]), ['webview']))
        ->toThrow(InvalidFragment::class, "[{$prop}] must be false or absent");
})->with([
    'local true' => ['local', true],
    'local as a string' => ['local', 'yes'],
    'local as 1' => ['local', 1],
    'local as an empty string' => ['local', ''],
    'php true, for older clients' => ['php', true],
    'php as 1' => ['php', 1],
]);

it('allows a web view whose local and php are false, null or absent', function (array $props) {
    expect(FragmentValidator::validate(Node::make('webview')->props(['src' => 'https://x.test', ...$props]), ['webview'])['props']['src'])
        ->toBe('https://x.test');
})->with([
    'absent' => [[]],
    'false' => [['local' => false, 'php' => false]],
    'null' => [['local' => null, 'php' => null]],
]);

it('resolves chrome logos and action images', function () {
    $clean = ChromeValidator::validate(Chrome::make()->navBar(NavBar::make()
        ->logo('img/logo.png')
        ->action(NavAction::make('me')->image('/avatars/me.png'))
        ->titleView(Node::make('image')->prop('src', 'brand.png'))));

    expect($clean['nav_bar']['logo']['src'])->toBe('http://localhost/img/logo.png');
    expect($clean['nav_bar']['actions'][0]['image'])->toBe('http://localhost/avatars/me.png');
    expect($clean['nav_bar']['title_view']['props']['src'])->toBe('http://localhost/brand.png');
});

it('refuses bad chrome media URLs', function (array $chrome) {
    expect(fn () => ChromeValidator::validate($chrome))->toThrow(InvalidFragment::class);
})->with([
    'logo scheme' => [['nav_bar' => ['logo' => ['src' => 'file:///logo.png']]]],
    'empty logo' => [['nav_bar' => ['logo' => ['src' => '']]]],
    'action data image' => [['nav_bar' => ['actions' => [['id' => 'x', 'image' => 'data:image/png;base64,AA==']]]]],
]);

it('resolves against the request host when rendering', function () {
    Asgard::component('app.native.screens.bad', BadFragments::class);

    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'regions' => ['image'], 'state' => ['src' => '/storage/promo.jpg']]))
        ->assertOk()
        ->assertJsonPath('fragments.image.fragment.props.src', 'http://localhost/storage/promo.jpg');
});

it('answers a bad media URL like any invalid fragment', function () {
    Asgard::component('app.native.screens.bad', BadFragments::class);

    $this->asgard(asgardBody(['component' => 'app.native.screens.bad', 'regions' => ['image'], 'state' => ['src' => 'javascript:alert(1)']]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment');
});
