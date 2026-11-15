<?php

namespace Native\Asgard\Tests;

use Illuminate\Testing\TestResponse;
use Native\Asgard\AsgardServiceProvider;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\BadChromeScreen;
use Native\Asgard\Tests\Fixtures\BadFragments;
use Native\Asgard\Tests\Fixtures\BareScreen;
use Native\Asgard\Tests\Fixtures\BladePromoScreen;
use Native\Asgard\Tests\Fixtures\BladeScreen;
use Native\Asgard\Tests\Fixtures\CacheScreen;
use Native\Asgard\Tests\Fixtures\ChannelScreen;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\ComputedScreen;
use Native\Asgard\Tests\Fixtures\CounterScreen;
use Native\Asgard\Tests\Fixtures\FeedScreen;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Native\Asgard\Tests\Fixtures\PromoScreen;
use Native\Asgard\Tests\Fixtures\RowsScreen;
use Native\Asgard\Tests\Fixtures\ServerMethodScreen;
use Native\Asgard\Tests\Fixtures\StateScreen;
use Native\Asgard\Tests\Fixtures\TopBarViewScreen;
use Native\Asgard\Tests\Fixtures\UploadScreen;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public const KEY = 'test-asgard-key';

    /** @var array{id: string, keys: SessionKeys}|null */
    public ?array $asgardSession = null;

    /** Bytes of the last sealed answer as it came off the wire, before opening. */
    public ?int $wireLength = null;

    /**
     * The session and request signature of the last stream opened, so
     * sseBlocks() can verify and decrypt its events.
     *
     * @var array{id: string, keys: SessionKeys, signature: string}|null
     */
    public static ?array $stream = null;

    protected function getPackageProviders($app)
    {
        return [AsgardServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('asgard.key', self::KEY);
        $app['config']->set('asgard.components', [
            'app.native.screens.home' => HomeScreen::class,
            'app.native.screens.bad' => BadFragments::class,
            'app.native.screens.child' => ChildScreen::class,
            'app.native.screens.counter' => CounterScreen::class,
            'app.native.screens.rows' => RowsScreen::class,
            'app.native.screens.blade' => BladeScreen::class,
            'app.native.screens.channel' => ChannelScreen::class,
            'app.native.screens.channel_two' => ChannelScreen::class,
            'app.native.screens.server_methods' => ServerMethodScreen::class,
            'app.native.screens.uploads' => UploadScreen::class,
            'app.native.screens.computed' => ComputedScreen::class,
            'app.native.screens.cache' => CacheScreen::class,
            'app.native.screens.state' => StateScreen::class,
        ]);
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
        $app['config']->set('asgard.blade.compiled', sys_get_temp_dir().'/asgard-region-views-'.getmypid());
        $app['config']->set('asgard.screens', [PromoScreen::class, BareScreen::class, BadChromeScreen::class, BladePromoScreen::class, TopBarViewScreen::class]);
    }

    protected function tearDown(): void
    {
        ComponentMetadata::flush();
        HomeScreen::$calls = [];
        self::$stream = null;
        ChildScreen::$calls = [];
        PromoScreen::$calls = [];
        ChannelScreen::$extra = [];
        ServerMethodScreen::$calls = [];
        UploadScreen::$calls = [];
        ComputedScreen::$mode = null;
        ComputedScreen::$totals = 0;
        StateScreen::$calls = [];
        CacheScreen::reset();
        FeedScreen::$items = ['a', 'b'];
        FeedScreen::$liked = [];

        parent::tearDown();
    }

    /**
     * A real handshake, as a client would do it. $elements is what the
     * client lists in `elements`, or null to leave it out.
     *
     * @param  list<string>|null  $elements
     * @return array{id: string, keys: SessionKeys}
     */
    public function handshake(?array $elements = null): array
    {
        [$private, $public] = Ecdh::generate();

        $response = $this->call('POST', '/_asgard/session', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'key' => self::KEY, 'protocols' => [1], 'accepts' => ['tree/json'], 'client_public' => Base64Url::encode($public),
            ...($elements === null ? [] : ['elements' => $elements]),
        ]))->assertOk();

        $serverPublic = Base64Url::decode($response->json('server_public'));
        $secret = SessionKeys::secret(Ecdh::agree($private, $serverPublic), $public, $serverPublic);

        return $this->asgardSession = ['id' => $response->json('session'), 'keys' => SessionKeys::fromSecret($secret)];
    }

    /**
     * Send a request the way a client does: handshake once, encrypt the body,
     * sign, then verify and decrypt the answer. The TestResponse holds the
     * plaintext, so assertions read as they would without encryption. A
     * signed answer that does not verify fails the test.
     *
     * $sign takes `created`, `nonce` (false for none) and `tamper`, a
     * closure that may change the sealed headers and body before sending.
     */
    public function asgard(array|string $body, string $method = 'QUERY', array $headers = [], string $endpoint = 'render', array $sign = []): TestResponse
    {
        $session = $this->asgardSession ?? $this->handshake();

        $covered = [];
        $extra = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), Wire::OPTIONAL_REQUEST_COMPONENTS, true)) {
                $covered[strtolower($name)] = $value;
            } else {
                $extra[$name] = $value;
            }
        }

        // Actions, calls and uploads are unsafe, so they sign a nonce by default.
        $unsafe = in_array($endpoint, ['call', 'upload'], true) || (is_array($body) && array_key_exists('action', $body));
        $nonce = $sign['nonce'] ?? ($unsafe ? Base64Url::encode(random_bytes(16)) : false);

        // Sign the authority the test request will really carry.
        $url = parse_url($this->prepareUrlForRequest("/_asgard/{$endpoint}"));
        $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        $sealed = Wire::sealRequest(
            $session['keys'], $session['id'], $method, $authority, "/_asgard/{$endpoint}",
            is_string($body) ? $body : json_encode($body),
            $covered,
            $sign['created'] ?? now()->getTimestamp(),
            $nonce === false ? null : $nonce,
        );

        if (isset($sign['tamper'])) {
            $sealed = ($sign['tamper'])($sealed);
        }

        $response = $this->sendSealed($sealed, $method, $endpoint, $extra);

        self::$stream = ['id' => $session['id'], 'keys' => $session['keys'], 'signature' => $sealed['signature']];

        if (! $response->headers->has('Signature')) {
            return $response;
        }

        $base = $response->baseResponse;
        $this->wireLength = strlen((string) $base->getContent());
        $base->setContent(Wire::openResponse(
            $session['keys'], $session['id'], $base->getStatusCode(),
            array_map(fn ($values) => implode(', ', $values), $base->headers->all()),
            (string) $base->getContent(), $sealed['signature'], now()->getTimestamp(),
        ));

        return TestResponse::fromBaseResponse($base);
    }

    /**
     * Put an already sealed request on the wire as it is. For replays.
     *
     * @param  array{headers: array<string, string>, body: string}  $sealed
     */
    public function sendSealed(array $sealed, string $method = 'QUERY', string $endpoint = 'render', array $extra = []): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        foreach ($sealed['headers'] + $extra as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }

        return $this->call($method, "/_asgard/{$endpoint}", [], [], [], $server, $sealed['body']);
    }

    public function asgardStream(array|string $body, string $method = 'POST', array $headers = [], array $sign = []): TestResponse
    {
        if ($method === 'POST') {
            $headers += ['X-Asgard-Method' => 'QUERY'];
        }

        return $this->asgard($body, $method, $headers, 'stream', $sign);
    }
}
