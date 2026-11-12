<?php

namespace Native\Asgard\Tests;

use Illuminate\Testing\TestResponse;
use Native\Asgard\AsgardServiceProvider;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Tests\Fixtures\BadFragments;
use Native\Asgard\Tests\Fixtures\ChildScreen;
use Native\Asgard\Tests\Fixtures\CounterScreen;
use Native\Asgard\Tests\Fixtures\HomeScreen;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [AsgardServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('asgard.components', [
            'app.native.screens.home' => HomeScreen::class,
            'app.native.screens.bad' => BadFragments::class,
            'app.native.screens.child' => ChildScreen::class,
            'app.native.screens.counter' => CounterScreen::class,
        ]);
    }

    protected function tearDown(): void
    {
        ComponentMetadata::flush();
        HomeScreen::$calls = [];
        ChildScreen::$calls = [];

        parent::tearDown();
    }

    public function asgard(array|string $body, string $method = 'QUERY', array $headers = [], string $endpoint = 'render'): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ASGARD_PROTOCOL' => '1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, "/_asgard/{$endpoint}", [], [], [], $server, is_string($body) ? $body : json_encode($body));
    }

    public function asgardStream(array|string $body, string $method = 'POST', array $headers = []): TestResponse
    {
        if ($method === 'POST') {
            $headers += ['X-Asgard-Method' => 'QUERY'];
        }

        return $this->asgard($body, $method, $headers, 'stream');
    }
}
