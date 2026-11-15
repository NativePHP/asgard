<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Support\Channels;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\ChannelScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('asgard.stream.lifetime', 3);
    config()->set('asgard.stream.poll_ms', 500);

    $this->clock = new FakeClock;
    $this->app->instance(StreamClock::class, $this->clock);
});

function channelBody(array $overrides = []): array
{
    return streamBody(array_merge([
        'component' => 'app.native.screens.channel',
        'regions' => ['title', 'body'],
        'state' => ['promo' => 42],
    ], $overrides));
}

/** @return list<array{string, string}> */
function idsAndRegions(string $content): array
{
    return array_map(fn ($e) => [$e['id'], $e['region']], sseEvents($content));
}

it('joins channels worked out from the stream state', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.7')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to('promo.42')->push();

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'], ['1', 'body'],
    ]);
});

it('works out the channels of each subscription from its own state', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.7')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to('promo.42')->push(ChannelScreen::class, 'body');

    $events = sseEvents($this->asgardStream(streamBody([], [
        subscription(['id' => 'p42', 'component' => 'app.native.screens.channel', 'regions' => ['title', 'body'], 'state' => ['promo' => 42]]),
        subscription(['id' => 'p7', 'component' => 'app.native.screens.channel', 'regions' => ['title', 'body'], 'state' => ['promo' => 7]]),
    ]))->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['subscription'], $e['region']], $events))->toBe([
        ['0', 'p42', 'title'], ['0', 'p42', 'body'], ['0', 'p7', 'title'], ['0', 'p7', 'body'],
        ['1', 'p7', 'title'], ['1', 'p7', 'body'],
        ['2', 'p42', 'body'],
    ]);
    expect(array_unique(array_column($events, 'event')))->toBe(['signal']);
});

it('renders a shared region as a guest on the stream, and with the user on render', function () {
    $user = (new User)->forceFill(['id' => 5]);
    $this->actingAs($user);

    $this->clock->onSleep[1] = fn () => Asgard::to('user.5')->push(ChannelScreen::class, 'viewer');

    $events = sseEvents($this->asgardStream(channelBody(['regions' => ['viewer']]))->streamedContent());

    expect(array_column($events, 'event'))->toBe(['fragment', 'fragment'])
        ->and(array_column(array_column(array_column($events, 'fragment'), 'props'), 'text'))->toBe([
            'Viewer guest / none of promo 42',
            'Viewer guest / none of promo 42',
        ]);

    // The user is back once the shared render is done.
    expect(auth()->id())->toBe(5);

    // Render is not the stream: it renders with the user, as always.
    $this->asgard(asgardBody(['component' => 'app.native.screens.channel', 'regions' => ['viewer'], 'state' => ['promo' => 42]]))
        ->assertOk()
        ->assertJsonPath('fragments.viewer.fragment.props.text', 'Viewer 5 / 5 of promo 42');

    // A region that is not shared is only signalled, so it never renders on the stream.
    $events = sseEvents($this->asgardStream(channelBody(['regions' => ['title']]))->streamedContent());
    expect(array_column($events, 'event'))->toBe(['signal']);
});

it('joins channels from the authenticated user, by name and by model', function () {
    $user = (new User)->forceFill(['id' => 5]);
    $this->actingAs($user);

    $this->clock->onSleep[1] = fn () => Asgard::to('user.9')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to('user.5')->push(ChannelScreen::class, 'title');
    $this->clock->onSleep[3] = fn () => Asgard::to($user)->push('app.native.screens.channel', 'body');

    $events = sseEvents($this->asgardStream(channelBody())->streamedContent());

    expect(array_map(fn ($e) => [$e['id'], $e['region']], $events))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'],
        ['2', 'body'],
    ]);
});

it('does not join user channels without an authenticated user', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('user.')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to((new User)->forceFill(['id' => 5]))->push();

    $events = sseEvents($this->asgardStream(channelBody())->streamedContent());

    expect($events)->toHaveCount(2);
});

it('names a model channel the way Laravel broadcasting does', function () {
    $user = (new User)->forceFill(['id' => 5]);

    expect(Channels::name($user))->toBe('Native.Asgard.Tests.Fixtures.User.5')
        ->and(Asgard::to($user, ['a', 'b'], 'a')->channels())->toBe(['Native.Asgard.Tests.Fixtures.User.5', 'a', 'b']);
});

it('narrows a channel push to a component and a region', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.42')->push('app.native.screens.channel_two');
    $this->clock->onSleep[2] = fn () => Asgard::to('promo.42')->push('app.native.screens.channel', 'body');
    $this->clock->onSleep[3] = fn () => Asgard::to('promo.42')->push('app.native.screens.channel');
    $this->clock->onSleep[4] = fn () => Asgard::to('promo.43')->push('app.native.screens.channel', 'title');

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'body'],
        ['2', 'title'], ['2', 'body'],
    ]);
});

it('pushes a class to every address it is registered under', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.42')->push(ChannelScreen::class, 'title');

    expect(idsAndRegions($this->asgardStream(channelBody(['component' => 'app.native.screens.channel_two']))->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'],
    ]);
});

it('reaches streams of any component with a channel-only push', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to(['promo.1', 'promo.42'])->push();

    expect(idsAndRegions($this->asgardStream(channelBody(['component' => 'app.native.screens.channel_two']))->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'], ['1', 'body'],
    ]);
});

it('still gets pushes to everyone', function () {
    $this->clock->onSleep[1] = fn () => Asgard::push('app.native.screens.channel', 'title');

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'],
    ]);
});

it('joins no channels by default', function () {
    $this->clock->onSleep[1] = fn () => Asgard::to('promo.0')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to('promo.0')->push(HomeScreen::class);

    expect(sseEvents($this->asgardStream(streamBody())->streamedContent()))->toHaveCount(2);
});

it('refuses bad channel pushes', function (Closure $push) {
    expect($push)->toThrow(InvalidArgumentException::class);
})->with([
    'no channels' => [fn () => Asgard::to()],
    'empty list' => [fn () => Asgard::to([])],
    'empty name' => [fn () => Asgard::to('')],
    'too long' => [fn () => Asgard::to(str_repeat('a', 201))],
    'control character' => [fn () => Asgard::to("promo\n42")],
    'not UTF-8' => [fn () => Asgard::to("promo\xff")],
    'not a string' => [fn () => Asgard::to(42)],
    'region without component' => [fn () => Asgard::to('promo.42')->push(null, 'title')],
    'unknown component' => [fn () => Asgard::to('promo.42')->push('app.native.screens.nope')],
    'unknown region' => [fn () => Asgard::to('promo.42')->push(ChannelScreen::class, 'nope')],
]);

it('accepts a 200 byte channel name', function () {
    expect(Channels::name(str_repeat('a', 200)))->toHaveLength(200);
});

it('throws on a bad channels() answer in debug', function () {
    ChannelScreen::$extra = [''];

    $this->asgardStream(channelBody())->assertStatus(500);
    expect($this->clock->sleeps)->toBe(0);
});

it('reports a bad channels() answer in production and keeps the good channels', function () {
    config()->set('app.debug', false);
    Exceptions::fake();
    ChannelScreen::$extra = ['', 5, ['nested'], 'extra.1'];

    $this->clock->onSleep[1] = fn () => Asgard::to('extra.1')->push(ChannelScreen::class, 'body');

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'body'],
    ]);

    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'ChannelScreen::channels() returned bad channels'));
});

it('joins at most 16 channels', function () {
    config()->set('app.debug', false);
    Exceptions::fake();
    // promo.42 plus extra.1 to extra.20, so extra.15 is the 16th.
    ChannelScreen::$extra = array_map(fn ($i) => "extra.{$i}", range(1, 20));

    $this->clock->onSleep[1] = fn () => Asgard::to('extra.16')->push();
    $this->clock->onSleep[2] = fn () => Asgard::to('extra.15')->push(ChannelScreen::class, 'title');

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'],
    ]);

    Exceptions::assertReported(InvalidArgumentException::class);

    config()->set('app.debug', true);
    $this->asgardStream(channelBody())->assertStatus(500);
});

it('drops repeated channels without complaint', function () {
    ChannelScreen::$extra = array_fill(0, 30, 'promo.42');

    $this->asgardStream(channelBody())->assertOk();
});

it('reads every counter a stream watches in one many() call', function () {
    $repository = new class(new ArrayStore) extends Repository
    {
        public array $calls = [];

        public function many(array $keys)
        {
            $this->calls[] = $keys;

            return parent::many($keys);
        }
    };
    $factory = new class($repository) implements Factory
    {
        public function __construct(public Repository $repository) {}

        public function store($name = null)
        {
            return $this->repository;
        }
    };

    $versions = new PushVersions($factory);
    $versions->bump('a.b', 'r1');
    $versions->bumpChannel('c', 'a.b');
    $versions->bumpChannel('d', 'a.b', 'r2');
    $versions->bumpChannel('d', 'other');

    $snapshot = $versions->snapshot('a.b', ['r1', 'r2'], ['c', 'd']);

    expect($repository->calls)->toHaveCount(1)
        ->and($repository->calls[0])->toHaveCount(5 + 2 * 3)
        ->and($snapshot)->toBe([
            'component' => [0, 0, 1, 0, 0],
            'regions' => ['r1' => [1, 0, 0], 'r2' => [0, 0, 1]],
        ]);
});

it('keeps channel keys apart from address keys and from each other', function () {
    $hash = sha1('promo.42');

    expect(PushVersions::channelKey('promo.42'))->toBe("asgard:push:@{$hash}")
        ->and(PushVersions::channelComponentKey('promo.42', 'a.b'))->toBe("asgard:push:@{$hash}|a.b")
        ->and(PushVersions::channelRegionKey('promo.42', 'a.b', 'r'))->toBe("asgard:push:@{$hash}|a.b#r")
        ->and(PushVersions::channelKey('a|b'))->not->toBe(PushVersions::channelComponentKey('a', 'b'));
});

it('pushes to channels from the asgard:push command', function () {
    $this->clock->onSleep[1] = fn () => Artisan::call('asgard:push', ['--to' => ['promo.1', 'promo.42']]);
    $this->clock->onSleep[2] = fn () => Artisan::call('asgard:push', ['component' => ChannelScreen::class, 'region' => 'body', '--to' => ['promo.42']]);
    $this->clock->onSleep[3] = fn () => Artisan::call('asgard:push', ['component' => ChannelScreen::class, '--to' => ['promo.7']]);

    expect(idsAndRegions($this->asgardStream(channelBody())->streamedContent()))->toBe([
        ['0', 'title'], ['0', 'body'],
        ['1', 'title'], ['1', 'body'],
        ['2', 'body'],
    ]);
});

it('checks the command arguments', function () {
    $this->artisan('asgard:push')->assertFailed();
    $this->artisan('asgard:push', ['--to' => ['promo.42']])->assertSuccessful();
    $this->artisan('asgard:push', ['--to' => ['']])->assertFailed();
    $this->artisan('asgard:push', ['component' => 'app.native.screens.nope', '--to' => ['promo.42']])->assertFailed();
    $this->artisan('asgard:push', ['component' => ChannelScreen::class, 'region' => 'nope', '--to' => ['promo.42']])->assertFailed();
});
