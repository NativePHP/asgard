<?php

use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Computed;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Server;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\ComputedScreen;

function computedCall(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'app.native.screens.computed',
        'method' => 'add',
        'args' => [5],
        'state' => ['items' => [1, 2], 'added' => 0],
    ], $overrides);
}

function computedUpload(): string
{
    $bytes = 'hello';
    $header = json_encode([
        'protocol' => 1, 'component' => 'app.native.screens.computed', 'method' => 'attach', 'args' => [],
        'state' => ['items' => [4]], 'filename' => 'a.txt', 'mime' => 'text/plain', 'size' => strlen($bytes),
    ]);

    return pack('N', strlen($header)).$header.$bytes;
}

// #[Computed]

it('finds #[Computed] methods and keeps them off every allowlist', function () {
    $metadata = ComponentMetadata::for(ComputedScreen::class);

    expect($metadata->computed)->toBe(['total', 'count'])
        ->and($metadata->servers)->not->toHaveKey('total')
        ->and($metadata->actions)->not->toHaveKey('total')
        ->and($metadata->uploads)->not->toHaveKey('total')
        ->and($metadata->regions)->not->toContain('total')
        ->and($metadata->properties)->not->toHaveKey('total');
});

it('sends changed props, then computed values, worked out after the method ran', function () {
    $response = $this->asgard(computedCall(), 'QUERY', [], 'call')->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"delta":{"items":[1,2,5],"added":1,"total":8,"count":3}}');
});

it('sends computed values even when nothing changed', function () {
    $response = $this->asgard(computedCall(['method' => 'nothing', 'args' => []]), 'QUERY', [], 'call')->assertOk();

    expect($response->getContent())->toBe('{"protocol":1,"delta":{"total":3,"count":2}}');
});

it('never lets state write a computed value', function () {
    $this->asgard(computedCall(['method' => 'nothing', 'args' => [], 'state' => ['items' => [1], 'total' => 99, 'count' => 99]]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['total' => 1, 'count' => 1]]);
});

it('refuses a computed method as a server method or an action', function () {
    $this->asgard(computedCall(['method' => 'total', 'args' => []]), 'QUERY', [], 'call')
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_method']);

    $this->asgard([
        'protocol' => 1, 'component' => 'app.native.screens.computed', 'region' => 'summary', 'state' => [],
        'action' => ['call' => 'total', 'args' => []],
    ])->assertNotFound()->assertExactJson(['error' => 'unknown_action']);

    expect(ComputedScreen::$totals)->toBe(0);
});

it('lets a region call a computed method like any other', function () {
    $this->asgard(['protocol' => 1, 'component' => 'app.native.screens.computed', 'region' => 'summary', 'state' => ['items' => [2, 3]]])
        ->assertOk()
        ->assertJsonPath('fragment.props.text', 'Total 5');
});

it('adds computed values to an upload answer', function () {
    $this->asgard(computedUpload(), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['added' => 1, 'total' => 4, 'count' => 1]]);
});

it('refuses a computed value that is not plain JSON as an invalid delta', function () {
    ComputedScreen::$mode = 'object';

    $this->asgard(computedCall(), 'QUERY', [], 'call')
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_delta')
        ->assertJsonPath('message', fn ($m) => str_contains($m, '#[Computed] [total]'));
});

it('treats a computed that throws like the method throwing', function () {
    ComputedScreen::$mode = 'throw';

    $this->asgard(computedCall(), 'QUERY', [], 'call')->assertStatus(500);

    ComputedScreen::$mode = 'invalid';

    $this->asgard(computedCall(), 'QUERY', [], 'call')
        ->assertStatus(422)
        ->assertExactJson(['error' => 'invalid_request', 'message' => 'total needs items']);
});

it('refuses a #[Computed] that breaks the rules', function (string $class, string $message) {
    expect(fn () => ComponentMetadata::for($class))->toThrow(LogicException::class, $message);
})->with([
    'same name as a prop' => [ComputedNamedLikeAProp::class, 'same name as a public prop'],
    'required parameter' => [ComputedWithParams::class, 'cannot have required parameters'],
    'also a server method' => [ComputedAndServer::class, 'cannot also be #[Server]'],
    'also an action' => [ComputedAndAction::class, 'cannot also be #[Action]'],
    'also an upload' => [ComputedAndUpload::class, 'cannot also be #[Upload]'],
    'also a region' => [ComputedAndRegion::class, 'cannot also be #[Region]'],
    'static' => [StaticComputed::class, 'public, non-static'],
    'protected' => [ProtectedComputed::class, 'public, non-static'],
]);

it('counts a computed method a parent declares', function () {
    expect(ComponentMetadata::for(InheritsComputed::class)->computed)->toBe(['total', 'count']);
});

// asgard.state

it('adds configured state after props and computed values, every answer', function () {
    config()->set('asgard.state', [
        'server_version' => [ComputedScreen::class, 'serverVersion'],
        'region' => fn () => 'eu',
        'flags' => ['beta' => true],
    ]);

    $response = $this->asgard(computedCall(), 'QUERY', [], 'call')->assertOk();

    expect($response->getContent())->toBe(
        '{"protocol":1,"delta":{"items":[1,2,5],"added":1,"total":8,"count":3,"server_version":"2.1.0","region":"eu","flags":{"beta":true}}}'
    );

    $this->asgard(computedUpload(), 'QUERY', [], 'upload')
        ->assertOk()
        ->assertJsonPath('delta.server_version', '2.1.0');
});

it('lets a component\'s own prop or computed win over a configured key', function () {
    config()->set('asgard.state', ['platform' => 'server', 'total' => 'server', 'added' => 'server', 'other' => 1]);

    // platform is an unchanged prop, so it is not sent at all.
    $this->asgard(computedCall(['state' => ['items' => [1], 'platform' => 'ios']]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['items' => [1, 5], 'added' => 1, 'total' => 6, 'count' => 2, 'other' => 1]]);
});

it('throws on a configured key that is not a property name', function (mixed $key) {
    $this->withoutExceptionHandling();
    config()->set('asgard.state', [$key => 'x']);

    expect(fn () => $this->asgard(computedCall(), 'QUERY', [], 'call'))
        ->toThrow(InvalidArgumentException::class, 'is not a property name');
})->with(['1st', 'has-dash', 'has space', 0, str_repeat('a', 101)]);

it('throws on a configured value that is not plain JSON', function (mixed $value) {
    $this->withoutExceptionHandling();
    config()->set('asgard.state', ['bad' => $value]);

    expect(fn () => $this->asgard(computedCall(), 'QUERY', [], 'call'))
        ->toThrow(InvalidArgumentException::class, 'asgard.state [bad]');
})->with([
    'object' => fn () => new stdClass,
    'nan' => [[NAN]],
    'closure giving an object' => fn () => fn () => new stdClass,
]);

it('takes null as no configured state', function () {
    config()->set('asgard.state', null);

    $this->asgard(computedCall(['method' => 'nothing', 'args' => []]), 'QUERY', [], 'call')
        ->assertOk()
        ->assertExactJson(['protocol' => 1, 'delta' => ['total' => 3, 'count' => 2]]);
});

class ComputedNamedLikeAProp extends ServerComponent
{
    public int $total = 0;

    #[Computed]
    public function total(): int
    {
        return 1;
    }
}

class ComputedWithParams extends ServerComponent
{
    #[Computed]
    public function total(int $from): int
    {
        return $from;
    }
}

class ComputedAndServer extends ServerComponent
{
    #[Computed]
    #[Server]
    public function total(): int
    {
        return 1;
    }
}

class ComputedAndAction extends ServerComponent
{
    #[Computed]
    #[Action]
    public function total(): int
    {
        return 1;
    }
}

class ComputedAndUpload extends ServerComponent
{
    #[Computed]
    #[Upload]
    public function total(?UploadedFile $file = null): int
    {
        return 1;
    }
}

class ComputedAndRegion extends ServerComponent
{
    #[Computed]
    #[Region('total')]
    public function total(): Node
    {
        return Node::text('1');
    }
}

class StaticComputed extends ServerComponent
{
    #[Computed]
    public static function total(): int
    {
        return 1;
    }
}

class ProtectedComputed extends ServerComponent
{
    #[Computed]
    protected function total(): int
    {
        return 1;
    }
}

class InheritsComputed extends ComputedScreen {}
