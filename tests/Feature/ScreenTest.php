<?php

use Native\Asgard\Blade\RegionViews;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavAction;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\Chrome\Tab;
use Native\Asgard\Chrome\TabBar;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Node;
use Native\Asgard\ScreenRegistry;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\StreamClock;
use Native\Asgard\Tests\Fixtures\BadUriScreen;
use Native\Asgard\Tests\Fixtures\BareScreen;
use Native\Asgard\Tests\Fixtures\ClashingScreen;
use Native\Asgard\Tests\Fixtures\FakeClock;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\Fixtures\NoAttributeScreen;
use Native\Asgard\Tests\Fixtures\PromoScreen;
use Native\Asgard\Tests\Fixtures\RegionScreen;

require_once __DIR__.'/../Fixtures/ScreenFixtures.php';

function screenBody(array $overrides = []): array
{
    return array_merge([
        'protocol' => 1,
        'component' => 'promos.show',
        'region' => 'screen',
        'state' => ['id' => '42'],
        'platform' => 'ios',
    ], $overrides);
}

// ── Rendering ───────────────────────────────────────

it('renders a screen body and its chrome as separate parts', function () {
    $response = $this->asgard(screenBody())->assertOk();

    expect($response->json('region'))->toBe('screen');
    expect($response->json('fragment.children.0'))->toBe(['type' => 'text', 'key' => 'title', 'props' => ['text' => 'Promo 42']]);
    expect($response->json('fragment.children.2.on_press'))->toBe(['navigate' => '/promos/43', 'transition' => 'fade']);
    expect($response->json('fragment.children.3.on_press'))->toBe(['back' => true]);

    expect($response->json('chrome'))->toBe([
        'native' => true,
        'nav_bar' => [
            'title' => 'Promo #42',
            'back' => true,
            'actions' => [
                ['id' => 'like', 'icon' => 'heart', 'a11y_label' => 'Like', 'on_press' => ['call' => 'like', 'args' => []]],
                ['id' => 'more', 'icon' => 'ellipsis', 'items' => [
                    ['id' => 'home', 'label' => 'Home', 'on_press' => ['navigate' => '/', 'replace' => true]],
                    ['id' => 'divider', 'divider' => true],
                    ['id' => 'report', 'label' => 'Report', 'destructive' => true, 'on_press' => ['call' => 'report', 'args' => [], 'target' => 'client']],
                ]],
            ],
        ],
        'tab_bar' => [
            'hidden' => true,
            'tabs' => [['id' => 'home', 'label' => 'Home', 'icon' => 'home', 'url' => '/']],
        ],
    ]);
});

it('sends an empty chrome object for a screen without chrome', function () {
    $response = $this->asgard(screenBody(['component' => 'native.asgard.tests.fixtures.bare_screen', 'state' => []]))->assertOk();

    expect($response->getContent())->toContain('"chrome":{}');
});

it('sends no chrome for a plain component', function () {
    expect($this->asgard(asgardBody())->assertOk()->json())->not->toHaveKey('chrome');
});

it('fills typed properties from route strings', function () {
    $this->asgard(screenBody(['state' => ['id' => '7', 'preview' => 'true', 'ref' => '12']]))
        ->assertOk()
        ->assertJsonPath('fragment.children.0.props.text', 'Promo 7');
});

it('refuses route strings that do not read as the property type', function (mixed $id) {
    $this->asgard(screenBody(['state' => ['id' => $id]]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_request');
})->with(['abc', '4.5', '1e3', ' 4', '007', '99999999999999999999']);

it('keeps a string property as the string it was sent', function () {
    $screen = new PromoScreen;
    Hydrator::hydrate($screen, ['ref' => '12', 'preview' => '0']);

    expect($screen->ref)->toBe('12')->and($screen->preview)->toBeFalse();
});

it('does not coerce state for a plain component', function () {
    $this->asgard(asgardBody(['state' => ['count' => '3']]))->assertStatus(422);
});

it('runs a screen action and answers with body and chrome', function () {
    $this->asgard(screenBody(['action' => ['call' => 'like', 'args' => [5]]]))
        ->assertOk()
        ->assertJsonPath('fragment.children.1.props.text', 'Likes 5')
        ->assertJsonPath('chrome.nav_bar.title', 'Promo #42');

    expect(PromoScreen::$calls)->toBe(['like', 'render']);
});

it('never runs render or chrome as an action', function (string $call) {
    $this->asgard(screenBody(['action' => ['call' => $call, 'args' => []]]))
        ->assertNotFound()
        ->assertExactJson(['error' => 'unknown_action']);
})->with(['render', 'chrome']);

it('answers only the screen region for a screen', function () {
    $this->asgard(screenBody(['region' => 'greeting']))->assertNotFound();
});

it('compresses a screen body unless its attribute says not to', function () {
    expect(ComponentMetadata::for(PromoScreen::class)->compresses('screen'))->toBeTrue();
    expect(ComponentMetadata::for(BareScreen::class)->compresses('screen'))->toBeFalse();
});

it('refuses chrome with unknown fields as an invalid fragment', function () {
    $this->asgard(screenBody(['component' => 'native.asgard.tests.fixtures.bad_chrome_screen', 'state' => []]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment');
});

it('sends chrome with each pushed screen event', function () {
    config()->set('asgard.stream.lifetime', 2);
    $clock = new FakeClock;
    $this->app->instance(StreamClock::class, $clock);
    $clock->onSleep[1] = fn () => Asgard::push(PromoScreen::class);

    $content = $this->asgardStream(streamBody([
        'component' => 'promos.show', 'regions' => ['screen'], 'state' => ['id' => '3'], 'platform' => 'android',
    ]))->assertOk()->streamedContent();

    $events = array_values(array_filter(sseBlocks($content), 'is_array'));

    expect($events)->toHaveCount(2);
    expect($events[0]['data']['chrome']['nav_bar']['title'])->toBe('Promo #3');
    expect($events[1]['data']['fragment']['children'][0]['props']['text'])->toBe('Promo 3');
});

// ── Registration ────────────────────────────────────

it('registers config screens as components at their address', function () {
    expect(Asgard::resolve('promos.show'))->toBe(PromoScreen::class);
    expect(Asgard::resolve('native.asgard.tests.fixtures.bare_screen'))->not->toBeNull();
});

it('lists routes in registration order', function () {
    expect(Asgard::routes())->toBe([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show'],
        ['uri' => '/', 'screen' => 'native.asgard.tests.fixtures.bare_screen'],
        ['uri' => '/bad-chrome', 'screen' => 'native.asgard.tests.fixtures.bad_chrome_screen'],
        ['uri' => '/blade-promos/{id}', 'screen' => 'blade_promos.show'],
        ['uri' => '/top-bar-view/{where}', 'screen' => 'native.asgard.tests.fixtures.top_bar_view_screen'],
    ]);
});

it('refuses screens it cannot route', function (string $class, string $message) {
    $registry = new ScreenRegistry(new ComponentRegistry, [PromoScreen::class]);

    expect(fn () => $registry->screen($class))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no attribute' => [NoAttributeScreen::class, 'needs a #[Screen'],
    'a route already taken' => [ClashingScreen::class, 'already taken'],
    'an optional parameter mid-route' => [BadUriScreen::class, 'must be a path'],
    'not a screen' => [stdClass::class, 'must extend'],
]);

it('refuses a screen whose address another component already has', function () {
    $registry = new ScreenRegistry(new ComponentRegistry(['promos.show' => HomeScreen::class]));

    expect(fn () => $registry->screen(PromoScreen::class))->toThrow(InvalidArgumentException::class, 'already used');
});

it('refuses #[Region] methods on a screen', function () {
    expect(fn () => ComponentMetadata::for(RegionScreen::class))->toThrow(LogicException::class, 'one body');
});

// ── The routes endpoint ─────────────────────────────

it('serves the route list sealed, with a version', function () {
    $response = $this->asgard(['protocol' => 1], 'POST', ['X-Asgard-Method' => 'QUERY'], 'routes')->assertOk();

    expect($response->json('protocol'))->toBe(1);
    expect($response->json('routes.0'))->toBe(['uri' => '/promos/{id}', 'screen' => 'promos.show']);
    expect($response->json('version'))->toMatch('/^[0-9a-f]{16}$/');
    expect($this->asgard(['protocol' => 1], 'QUERY', [], 'routes')->json('version'))->toBe($response->json('version'));
});

it('refuses an unsigned route list request', function () {
    $this->call('POST', '/_asgard/routes', [], [], [], ['HTTP_X_ASGARD_METHOD' => 'QUERY'], '{"protocol":1}')
        ->assertStatus(419);
});

it('refuses a route list request with anything but the protocol', function (array $body) {
    $this->asgard($body, 'QUERY', [], 'routes')->assertStatus(422);
})->with([[['protocol' => 2]], [['protocol' => 1, 'component' => 'x']], [[]]]);

// ── Navigation slots ────────────────────────────────

it('builds navigation slots on nodes', function () {
    expect(Node::button('a')->onPressNavigate('/x?y=1', replace: true, transition: 'slide_from_bottom')->toArray()['on_press'])
        ->toBe(['navigate' => '/x?y=1', 'replace' => true, 'transition' => 'slide_from_bottom'])
        ->and(Node::button('a')->onLongPressNavigate('/x')->toArray()['on_long_press'])->toBe(['navigate' => '/x'])
        ->and(Node::button('a')->onDoubleTapNavigate('/x')->toArray()['on_double_tap'])->toBe(['navigate' => '/x'])
        ->and(Node::button('a')->onPressBack('fade')->toArray()['on_press'])->toBe(['back' => true, 'transition' => 'fade']);
});

it('refuses navigation to anything but an app path', function (string $uri) {
    expect(fn () => Node::button('a')->onPressNavigate($uri))->toThrow(InvalidFragment::class, 'on_press');
})->with(['https://evil.test', '//evil.test/x', 'promos/1', '/a b', "/a\nb", '/a\\b', '']);

it('refuses an unknown transition', function () {
    expect(fn () => Node::button('a')->onPressNavigate('/x', transition: 'spin'))->toThrow(InvalidFragment::class);
});

it('validates navigation slots in fragments', function (array $slot, string $field = 'on_press') {
    expect(fn () => FragmentValidator::validate(['type' => 'button', $field => $slot]))
        ->toThrow(InvalidFragment::class);
})->with([
    'a web URL' => [['navigate' => 'https://x.test']],
    'an extra key' => [['navigate' => '/x', 'data' => []]],
    'replace not a bool' => [['navigate' => '/x', 'replace' => 'yes']],
    'back not true' => [['back' => false]],
    'back with a uri' => [['back' => true, 'navigate' => '/x']],
    'navigation on change' => [['navigate' => '/x'], 'on_change'],
    'navigation on submit' => [['navigate' => '/x'], 'on_submit'],
]);

it('keeps a good navigation slot in a fragment', function () {
    expect(FragmentValidator::validate(['type' => 'button', 'on_long_press' => ['navigate' => '/x', 'replace' => false]]))
        ->toBe(['type' => 'button', 'on_long_press' => ['navigate' => '/x', 'replace' => false]]);
});

// ── Chrome validation ───────────────────────────────

it('validates everything the chrome builders can say', function () {
    $chrome = Chrome::make()
        ->native()
        ->font('Lobster-Regular')
        ->navBar(NavBar::make()
            ->title('T')->subtitle('S')->back()->hidden(false)
            ->logo('logo.png', 32)
            ->titleView(Node::text('Brand'))
            ->backgroundColor('#000')->textColor('#fff')->font('Inter')->elevation(2)
            ->displayMode('large')->scrollBehavior('collapse')
            ->searchBar('Search', 'search', 200)
            ->action(NavAction::make('me')->image('https://cdn.test/me.png')->icon('person', ios: 'person.circle', android: 'account_circle')->disabled()))
        ->tabBar(TabBar::make()
            ->highlight('home')->activeColor('#f00')->backgroundColor('#111')->textColor('#eee')->font('Inter')
            ->labelVisibility('selected')->dark()->minimizeOnScroll()->presentation('acme:pill', ['radius' => 12])
            ->add(Tab::link('Home', '/', icon: 'home')->badge('3', '#f00')->news()->active())
            ->add(Tab::action('Compose', icon: 'plus')->onPressNavigate('/compose'))
            ->add(Tab::search('Search', icon: 'search', placeholder: 'Find'))
            ->add(Tab::link('Promo', '/promos/1')->id('promo')))
        ->bottomBar(Node::row(Node::button('Send')->onPress('send')))
        ->tabAccessory([Node::text('Now playing')]);

    $clean = ChromeValidator::validate($chrome);

    expect($clean)->toBe($chrome->toArray());
    expect($clean['nav_bar']['search'])->toBe(['placeholder' => 'Search', 'debounce_ms' => 200, 'on_query' => ['call' => 'search', 'args' => []]]);
    expect($clean['tab_bar']['tabs'][1]['on_press'])->toBe(['navigate' => '/compose']);
    expect($clean['tab_bar']['presentation'])->toBe(['id' => 'acme:pill', 'configuration' => ['radius' => 12]]);
});

it('treats null chrome as none', function () {
    expect(ChromeValidator::validate(null))->toBe([]);
});

it('refuses bad chrome', function (array $chrome) {
    expect(fn () => ChromeValidator::validate($chrome))->toThrow(InvalidFragment::class);
})->with([
    'unknown top-level key' => [['layout' => 'tabs']],
    'native not a bool' => [['native' => 1]],
    'unknown nav bar key' => [['nav_bar' => ['colour' => 'red']]],
    'bad display mode' => [['nav_bar' => ['display_mode' => 'huge']]],
    'elevation out of range' => [['nav_bar' => ['elevation' => 999]]],
    'action without id' => [['nav_bar' => ['actions' => [['label' => 'x']]]]],
    'action with string press' => [['nav_bar' => ['actions' => [['id' => 'x', 'on_press' => 'save']]]]],
    'action navigating to the web' => [['nav_bar' => ['actions' => [['id' => 'x', 'on_press' => ['navigate' => 'https://x.test']]]]]],
    'nested menu items' => [['nav_bar' => ['actions' => [['id' => 'x', 'items' => [['id' => 'y', 'items' => []]]]]]]],
    'image not https' => [['nav_bar' => ['actions' => [['id' => 'x', 'image' => 'javascript:alert(1)']]]]],
    'divider with a press' => [['nav_bar' => ['actions' => [['id' => 'd', 'divider' => true, 'on_press' => ['back' => true]]]]]],
    'search query that navigates' => [['nav_bar' => ['search' => ['on_query' => ['navigate' => '/x']]]]],
    'title view with chrome inside' => [['nav_bar' => ['title_view' => ['type' => 'top_bar']]]],
    'bottom bar with a region' => [['bottom_bar' => ['type' => 'server_region']]],
    'tab without label' => [['tab_bar' => ['tabs' => [['id' => 'a']]]]],
    'tab with a web url' => [['tab_bar' => ['tabs' => [['id' => 'a', 'label' => 'A', 'url' => 'https://x.test']]]]],
    'too many tabs' => [['tab_bar' => ['tabs' => array_fill(0, 11, ['id' => 'a', 'label' => 'A'])]]],
    'bad presentation id' => [['tab_bar' => ['presentation' => ['id' => 'Not Valid']]]],
    'presentation config as a list' => [['tab_bar' => ['presentation' => ['id' => 'a', 'configuration' => [1, 2]]]]],
    'bad label visibility' => [['tab_bar' => ['label_visibility' => 'sometimes']]],
    'a list instead of an object' => [[['nav_bar' => []]]],
]);

// ── Blade screens ───────────────────────────────────

it('renders a screen body and chrome views from Blade', function () {
    $response = $this->asgard(screenBody(['component' => 'blade_promos.show', 'state' => ['id' => '42']]))->assertOk();

    expect($response->json('fragment'))->toBe([
        'type' => 'column',
        'class' => 'p-4',
        'children' => [
            ['type' => 'text', 'key' => 'title', 'props' => ['text' => 'Promo 42']],
            ['type' => 'text', 'props' => ['text' => 'Likes 0']],
            ['type' => 'button', 'props' => ['label' => 'More'], 'on_press' => ['navigate' => '/blade-promos/43', 'transition' => 'fade']],
            ['type' => 'button', 'props' => ['label' => 'Close'], 'on_press' => ['back' => true]],
            ['type' => 'button', 'props' => ['label' => 'Like'], 'on_press' => ['call' => 'like', 'args' => []]],
        ],
    ]);

    expect($response->json('chrome'))->toBe([
        'native' => true,
        'nav_bar' => ['title' => 'Promo #42'],
        'bottom_bar' => [
            'type' => 'row',
            'class' => 'p-2',
            'children' => [['type' => 'button', 'props' => ['label' => 'Home'], 'on_press' => ['navigate' => '/', 'replace' => true]]],
        ],
        'tab_accessory' => ['type' => 'button', 'props' => ['label' => 'Promo 42'], 'on_press' => ['call' => 'bump', 'args' => []]],
    ]);
});

it('runs an action on a Blade screen', function () {
    $this->asgard(screenBody([
        'component' => 'blade_promos.show', 'state' => ['id' => '1', 'likes' => 4], 'action' => ['call' => 'like', 'args' => []],
    ]))
        ->assertOk()
        ->assertJsonPath('fragment.children.1.props.text', 'Likes 5');
});

it('streams a Blade screen with its chrome', function () {
    config()->set('asgard.stream.lifetime', 1);
    $this->app->instance(StreamClock::class, new FakeClock);

    $content = $this->asgardStream(streamBody([
        'component' => 'blade_promos.show', 'regions' => ['screen'], 'state' => ['id' => '3'],
    ]))->assertOk()->streamedContent();

    $event = array_values(array_filter(sseBlocks($content), 'is_array'))[0]['data'];

    expect($event['fragment']['children'][0]['props']['text'])->toBe('Promo 3')
        ->and($event['chrome']['bottom_bar']['children'][0]['on_press'])->toBe(['navigate' => '/', 'replace' => true]);
});

it('refuses chrome tags in a screen view, body or chrome', function (string $where) {
    $this->asgard(screenBody(['component' => 'native.asgard.tests.fixtures.top_bar_view_screen', 'state' => ['where' => $where]]))
        ->assertStatus(500)
        ->assertJsonPath('error', 'invalid_fragment')
        ->assertSee('element type [top_bar] is not allowed');
})->with(['body', 'bar']);

it('lets a screen body view through when only the chrome is plain', function () {
    $this->asgard(screenBody(['component' => 'native.asgard.tests.fixtures.top_bar_view_screen', 'state' => ['where' => 'ok']]))
        ->assertOk()
        ->assertJsonPath('chrome.bottom_bar.type', 'row');
});

it('renders chrome views given in the array form too', function () {
    $chrome = app(RegionViews::class)->chrome(
        ['bottom_bar' => view('screens.promo-bar')],
        new BareScreen,
    );

    expect(ChromeValidator::validate($chrome)['bottom_bar']['type'])->toBe('row');
});

it('refuses to serialize a chrome view nobody rendered', function () {
    expect(fn () => Chrome::make()->bottomBar(view('screens.promo-bar'))->toArray())
        ->toThrow(InvalidFragment::class, 'must be rendered');
});
