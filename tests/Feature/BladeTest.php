<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Native\Asgard\Blade\Elements;
use Native\Asgard\Blade\NativeTagPrecompiler;
use Native\Asgard\Blade\RegionEngine;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\BladeScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;

/**
 * Render a Blade string as a region view and validate the result, the way
 * the render controller does.
 */
function region(string $blade, array $data = [], ?ServerComponent $component = null): array
{
    $dir = sys_get_temp_dir().'/asgard-inline-views-'.getmypid();
    @mkdir($dir, 0777, true);
    View::addLocation($dir);

    $name = 'inline_'.md5($blade);
    if (! is_file("{$dir}/{$name}.blade.php")) {
        file_put_contents("{$dir}/{$name}.blade.php", $blade);
    }

    $component ??= new BladeScreen;

    // These tests are about compiling tags, so the client takes every type.
    return FragmentValidator::validate(app(RegionViews::class)->resolve(view($name, $data), $component), [...Elements::DEFAULT, 'gizmo', 'map_view']);
}

it('compiles prefixed and bare tags to the same nodes', function () {
    $prefixed = region('<native:column class="p-2"><native:row><native:spacer /></native:row></native:column>');
    $bare = region('<column class="p-2"><row><spacer /></row></column>');

    expect($prefixed)->toBe($bare)->toBe([
        'type' => 'column',
        'class' => 'p-2',
        'children' => [['type' => 'row', 'children' => [['type' => 'spacer']]]],
    ]);
});

it('turns kebab-case tags into snake_case types', function () {
    expect(region('<scroll-view><native:progress-bar value="0.5" /></scroll-view>'))->toBe([
        'type' => 'scroll_view',
        'children' => [['type' => 'progress_bar', 'props' => ['value' => '0.5']]],
    ]);
});

it('accepts any type with the native prefix, and extra bare tags from config', function () {
    config(['asgard.blade.elements' => ['map_view']]);
    app()->forgetInstance('asgard.blade.compiler');
    app()->forgetInstance(RegionEngine::class);
    app()->forgetInstance(RegionViews::class);

    expect(region('<column><native:gizmo /><map-view zoom="3" /></column>'))->toBe([
        'type' => 'column',
        'children' => [['type' => 'gizmo'], ['type' => 'map_view', 'props' => ['zoom' => '3']]],
    ]);
});

it('nests children through loops and conditionals', function () {
    $fragment = region(<<<'BLADE'
        <column>
            @foreach ($rows as $row)
                <row key="{{ $row }}">
                    @if ($row === 'b')
                        <icon name="star" />
                    @endif
                    <text>{{ $row }}</text>
                </row>
            @endforeach
        </column>
        BLADE, ['rows' => ['a', 'b']]);

    expect($fragment)->toBe([
        'type' => 'column',
        'children' => [
            ['type' => 'row', 'key' => 'a', 'children' => [['type' => 'text', 'props' => ['text' => 'a']]]],
            ['type' => 'row', 'key' => 'b', 'children' => [
                ['type' => 'icon', 'props' => ['name' => 'star']],
                ['type' => 'text', 'props' => ['text' => 'b']],
            ]],
        ],
    ]);
});

it('captures text slots with interpolation, decoded and collapsed', function () {
    $fragment = region(<<<'BLADE'
        <column>
            <text class="text-lg">
                Hello,   {{ $who }}
                &amp; welcome
            </text>
            <text :text="$who" />
            <text class="whitespace-pre-wrap">  keep  me  </text>
            <button>  Save {{ $who }} </button>
        </column>
        BLADE, ['who' => 'Tom & Jerry <3']);

    expect($fragment['children'])->toBe([
        ['type' => 'text', 'props' => ['text' => 'Hello, Tom & Jerry <3 & welcome'], 'class' => 'text-lg'],
        ['type' => 'text', 'props' => ['text' => 'Tom & Jerry <3']],
        ['type' => 'text', 'props' => ['text' => '  keep  me  '], 'class' => 'whitespace-pre-wrap'],
        ['type' => 'button', 'props' => ['label' => 'Save Tom & Jerry <3']],
    ]);
});

it('builds nested text runs like the device does', function () {
    expect(region('<text>Use <text class="font-bold">{{ $what }}</text> now</text>', ['what' => 'this']))->toBe([
        'type' => 'text',
        'children' => [
            ['type' => 'text', 'props' => ['text' => 'Use ']],
            ['type' => 'text', 'props' => ['text' => 'this'], 'class' => 'font-bold'],
            ['type' => 'text', 'props' => ['text' => ' now']],
        ],
    ]);
});

it('maps class and key, including dynamic and native:key forms', function () {
    $fragment = region(<<<'BLADE'
        <column :class="['p-4', 'hidden' => $hidden, 'gap-2' => true]">
            <row :key="$id" />
            <row native:key="row-{{ $id }}" />
            <row key="plain" class="" />
        </column>
        BLADE, ['hidden' => false, 'id' => 7]);

    expect($fragment)->toBe([
        'type' => 'column',
        'class' => 'p-4 gap-2',
        'children' => [
            ['type' => 'row', 'key' => 7],
            ['type' => 'row', 'key' => 'row-7'],
            ['type' => 'row', 'key' => 'plain'],
        ],
    ]);
});

it('keeps other attributes as props under the name they were written with', function () {
    expect(region('<image src="/a.png" :width="120" rounded alt-text="A {{ $x }}" />', ['x' => 'cat']))->toBe([
        'type' => 'image',
        'props' => ['src' => 'http://localhost/a.png', 'width' => 120, 'rounded' => true, 'alt-text' => 'A cat'],
    ]);
});

it('makes @press a server action and @press.client a client action', function () {
    $fragment = region(<<<'BLADE'
        <column>
            <button @press="save">A</button>
            <button @press.client="celebrate">B</button>
            <pressable @tap="open(1)" @longTap.client="peek" @doubleTap="like" />
            <toggle @change="toggled" />
            <bare-text-input @submit.client="send('now')" />
        </column>
        BLADE);

    expect($fragment['children'])->toBe([
        ['type' => 'button', 'props' => ['label' => 'A'], 'on_press' => ['call' => 'save', 'args' => []]],
        ['type' => 'button', 'props' => ['label' => 'B'], 'on_press' => ['call' => 'celebrate', 'args' => [], 'target' => 'client']],
        ['type' => 'pressable',
            'on_press' => ['call' => 'open', 'args' => [1]],
            'on_long_press' => ['call' => 'peek', 'args' => [], 'target' => 'client'],
            'on_double_tap' => ['call' => 'like', 'args' => []],
        ],
        ['type' => 'toggle', 'on_change' => ['call' => 'toggled', 'args' => []]],
        ['type' => 'bare_text_input', 'on_submit' => ['call' => 'send', 'args' => ['now'], 'target' => 'client']],
    ]);
});

it('parses literal args', function () {
    $fragment = region(<<<'BLADE'
        <column>
            <pressable @press="pick('a', 2, -3, 1.5, true, FALSE, null, [1, 'x' => [2]], ['k' => 'v', ])" />
            <pressable @press.client='pick("b", "c")' />
        </column>
        BLADE);

    expect($fragment['children'][0]['on_press'])->toBe([
        'call' => 'pick',
        'args' => ['a', 2, -3, 1.5, true, false, null, [1, 'x' => [2]], ['k' => 'v']],
    ])->and($fragment['children'][1]['on_press'])->toBe([
        'call' => 'pick', 'args' => ['b', 'c'], 'target' => 'client',
    ]);
});

it('passes interpolated args as values, not text', function () {
    $fragment = region('<pressable @press="pick({{ $id }}, {{ $label }}, [{{ $id }}], {{ $tags }})" />', [
        'id' => 42,
        'label' => "x', 'injected",
        'tags' => ['a', 'b'],
    ]);

    expect($fragment['on_press'])->toBe([
        'call' => 'pick',
        'args' => [42, "x', 'injected", [42], ['a', 'b']],
    ]);
});

it('refuses args that are not literals or whole interpolations', function (string $attr, string $message) {
    expect(fn () => region("<pressable {$attr} />", ['id' => 1]))
        ->toThrow(InvalidFragment::class, $message);
})->with([
    'a function call' => ['@press="pick(strtoupper(\'a\'))"', '[strtoupper] is not allowed'],
    'a variable' => ['@press="pick($id)"', 'is not allowed'],
    'a constant' => ['@press="pick(PHP_VERSION)"', '[PHP_VERSION] is not allowed'],
    'interpolation inside quotes' => ['@press="pick(\'id-{{ $id }}\')"', 'must be a whole argument'],
    'a dynamic name' => ['@press="{{ $id }}"', 'cannot be dynamic'],
    'not a call' => ['@press="1 + 1"', 'expected a method name'],
    'deep nesting' => ['@press="pick([[[[[1]]]]])"', 'is not allowed'],
    'interpolated string' => ['@press="pick(&quot;{$id}&quot;)"', 'is not allowed'],
]);

it('still runs Node checks on the parsed call', function () {
    expect(fn () => region('<pressable @press="pick({{ $obj }})" />', ['obj' => new stdClass]))
        ->toThrow(InvalidFragment::class, 'args[0]');
});

it('refuses events with no fragment slot', function (string $attr, string $event) {
    expect(fn () => region("<pressable {$attr} />"))
        ->toThrow(InvalidFragment::class, "@{$event} has no fragment callback slot");
})->with([
    ['@swipe="go"', 'swipe'],
    ['@pressDown="go"', 'pressDown'],
    ['@tapUp="go"', 'pressUp'],
    ['@order-shipped="go"', 'order-shipped'],
]);

it('refuses directives that only make sense on the device', function (string $attr, string $name) {
    expect(fn () => region("<bare-text-input {$attr} />"))
        ->toThrow(InvalidFragment::class, "{$name} is not supported in a server region view");
})->with([
    ['native:model="name"', 'native:model'],
    ['@model="name"', '@model'],
    ['native:poll.2s', 'native:poll'],
]);

// ── @navigate ───────────────────────────────────────

it('compiles @navigate to the on_press navigation slot', function () {
    $fragment = region(<<<'BLADE'
        <column>
            <button @navigate="/promos/{{ $next }}">Next</button>
            <button @navigate.replace='/home'>Home</button>
            <pressable @navigate.slideFromBottom.replace="/sheets/{{ $next }}/edit?tab={{ $tab }}" />
            <button @navigate.back>Back</button>
            <button @navigate.back.fade>Fade back</button>
            <pressable @navigate="{{ $path }}" @longPress="peek" />
        </column>
        BLADE, ['next' => 7, 'tab' => 'b', 'path' => '/from/var']);

    expect(array_column($fragment['children'], 'on_press'))->toBe([
        ['navigate' => '/promos/7'],
        ['navigate' => '/home', 'replace' => true],
        ['navigate' => '/sheets/7/edit?tab=b', 'replace' => true, 'transition' => 'slide_from_bottom'],
        ['back' => true],
        ['back' => true, 'transition' => 'fade'],
        ['navigate' => '/from/var'],
    ])->and($fragment['children'][5]['on_long_press'])->toBe(['call' => 'peek', 'args' => []]);
});

it('still checks the interpolated path is an app path', function (mixed $path, string $message) {
    expect(fn () => region('<button @navigate="{{ $path }}">Go</button>', ['path' => $path]))
        ->toThrow(InvalidFragment::class, $message);
})->with([
    'a web URL' => ['https://evil.test/x', 'must be an app path'],
    'protocol relative' => ['//evil.test/x', 'must be an app path'],
    'not a string' => [42, 'must be a string, got int'],
]);

it('refuses @navigate forms and modifiers a server view cannot use', function (string $attr, string $message) {
    expect(fn () => region("<column><button {$attr}>Go</button></column>"))
        ->toThrow(InvalidFragment::class, $message);
})->with([
    'exitToWeb' => ['@navigate.exitToWeb="/web"', 'cannot leave the native app'],
    'an unknown modifier' => ['@navigate.sideways="/x"', 'unknown modifier [sideways]'],
    'two transitions' => ['@navigate.fade.morph="/x"', 'at most one transition'],
    'back with a path' => ['@navigate.back="/x"', '.back takes no path'],
    'back and replace' => ['@navigate.back.replace', 'cannot be combined'],
    'no path' => ['@navigate', 'expected a path'],
    'a PHP expression' => ['@navigate="\'/x\', [\'a\' => 1]"', 'not a PHP expression'],
    'the paren form' => ["@navigate('/x')", '@navigate(...) is not supported'],
    'a relative path' => ['@navigate="promos/1"', 'not a PHP expression'],
]);

it('refuses @navigate and @press on one element', function (string $press) {
    expect(fn () => region("<button @navigate=\"/x\" {$press}>Go</button>"))
        ->toThrow(InvalidFragment::class, 'has both @navigate and @press');
})->with(['@press="save"', '@press.client="save"', '@tap="save"']);

it('leaves @navigated alone as an event with no slot', function () {
    expect(fn () => region('<pager @navigated="seen" />'))
        ->toThrow(InvalidFragment::class, '@navigated has no fragment callback slot');
});

it('refuses unknown hyphenated bare tags in debug', function () {
    expect(fn () => region('<column><fancy-widget /></column>'))
        ->toThrow(InvalidFragment::class, '<fancy-widget>');
});

it('binds $this and exposes public properties as variables', function () {
    $component = new BladeScreen;
    $component->name = 'Ada';
    $component->count = 3;

    expect(region('<text>{{ $this->name }} {{ $name }} {{ $count }} {{ $this->region() }}</text>', [], $component))
        ->toBe(['type' => 'text', 'props' => ['text' => 'Ada Ada 3']]);
    expect(region('<text>{{ $name }}</text>', ['name' => 'passed'], $component)['props']['text'])->toBe('passed');
});

it('returns a list when the view has several roots, including from an @include', function () {
    expect(region("<text>one</text>\n@include('regions.partial', ['label' => 'two'])"))->toBe([
        ['type' => 'text', 'props' => ['text' => 'one']],
        ['type' => 'button', 'props' => ['label' => 'two'], 'on_press' => ['call' => 'bump', 'args' => []]],
    ]);
});

it('refuses a view that builds nothing, and one that is not Blade', function () {
    expect(fn () => region('just some words'))->toThrow(InvalidFragment::class, 'built no elements');

    $dir = sys_get_temp_dir().'/asgard-inline-views-'.getmypid();
    @mkdir($dir, 0777, true);
    file_put_contents("{$dir}/plain_php.php", '<?php echo "hi";');
    View::addLocation($dir);

    expect(fn () => app(RegionViews::class)->render(view('plain_php'), new BladeScreen))
        ->toThrow(InvalidFragment::class, 'must be a Blade view');
});

it('still rejects screen chrome written in Blade', function () {
    expect(fn () => region('<column><top-bar title="x" /></column>'))
        ->toThrow(InvalidFragment::class, 'element type [top_bar] is not allowed');
    expect(fn () => region('<native:server-region />'))
        ->toThrow(InvalidFragment::class, 'element type [server_region] is not allowed');
});

it('strips internal props written in Blade', function () {
    expect(region('<button native-id="x" label="Go" />'))->toBe(['type' => 'button', 'props' => ['label' => 'Go']]);
});

it('leaves ordinary web views alone', function () {
    // A region render first, so the precompiler has been active in this process.
    region('<text>hi</text>');

    $html = view('web.page', ['who' => 'Sam'])->render();

    expect($html)->toContain('<text>Sam</text>')
        ->toContain('<button type="button" @click="go">Go</button>')
        ->toContain('<column>raw</column>')
        ->and(NativeTagPrecompiler::active())->toBeFalse()
        ->and(View::getEngineResolver()->resolve('blade'))->not->toBeInstanceOf(RegionEngine::class)
        ->and(file_get_contents(Blade::getCompiledPath(View::getFinder()->find('web.page'))))
        ->not->toContain(NativeTagPrecompiler::COMPILED_MARKER);
});

it('compiles a view used both ways into separate files', function () {
    $html = view('regions.partial', ['label' => 'x'])->render();
    $nodes = region('<column>@include(\'regions.partial\', [\'label\' => \'y\'])</column>');

    $path = View::getFinder()->find('regions.partial');

    expect($html)->toContain('<button')
        ->and($nodes['children'][0]['props']['label'])->toBe('y')
        ->and(Blade::getCompiledPath($path))->not->toBe(app('asgard.blade.compiler')->getCompiledPath($path))
        ->and(view('regions.partial', ['label' => 'z'])->render())->toContain('<button');
});

it('copies the app\'s custom directives', function () {
    Blade::directive('shout', fn ($e) => "<?php echo strtoupper({$e}); ?>");
    app()->forgetInstance('asgard.blade.compiler');
    app()->forgetInstance(RegionEngine::class);
    app()->forgetInstance(RegionViews::class);

    expect(region("<text>@shout('hey')</text>")['props']['text'])->toBe('HEY');
});

// End to end, through the encrypted render endpoint.

it('renders a Blade region to the same fragment as the equivalent Node tree', function () {
    $state = ['name' => 'Simon', 'count' => 3];

    $blade = $this->asgard(asgardBody(['component' => 'app.native.screens.blade', 'regions' => ['stats'], 'state' => $state]))
        ->assertOk();
    $nodes = $this->asgard(asgardBody(['component' => 'app.native.screens.blade', 'regions' => ['nodes'], 'state' => $state]))
        ->assertOk();

    expect($blade->json('fragments.stats.fragment'))->toBe($nodes->json('fragments.nodes.fragment'))
        ->and($blade->json('fragments.stats.fragment.children.4.on_press'))->toBe(['call' => 'bump', 'args' => [2]])
        ->and($blade->json('fragments.stats.fragment.children.5.on_press'))->toBe(['call' => 'celebrate', 'args' => ['Simon'], 'target' => 'client']);
});

it('runs a server action pressed in a Blade region', function () {
    $this->asgard(asgardBody([
        'component' => 'app.native.screens.blade', 'regions' => ['stats'],
        'state' => ['count' => 1], 'action' => ['call' => 'bump', 'args' => [2]],
    ]))
        ->assertOk()
        ->assertJsonPath('fragments.stats.fragment.children.1.props.text', 'Count: 3');
});

it('returns a list fragment from a Blade view with several roots', function () {
    $this->asgard(asgardBody(['component' => 'app.native.screens.blade', 'regions' => ['many']]))
        ->assertOk()
        ->assertJsonPath('fragments.many.fragment', [
            ['type' => 'text', 'props' => ['text' => 'one']],
            ['type' => 'button', 'props' => ['label' => 'two'], 'on_press' => ['call' => 'bump', 'args' => []]],
        ]);
});

it('answers invalid_fragment for chrome in a Blade region', function () {
    $this->asgard(asgardBody(['component' => 'app.native.screens.blade', 'regions' => ['chrome']]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment');
});

it('streams Blade regions', function () {
    config()->set('asgard.stream.lifetime', 1);
    $this->app->instance(StreamClock::class, new FakeClock);

    $response = $this->asgardStream(streamBody(['component' => 'app.native.screens.blade', 'regions' => ['stats'], 'state' => ['name' => 'Simon']]));

    $fragments = sseEvents($response->streamedContent());

    expect($fragments[0]['region'])->toBe('stats')
        ->and($fragments[0]['fragment']['children'][0]['props']['text'])->toBe('Hello, Simon');
});
