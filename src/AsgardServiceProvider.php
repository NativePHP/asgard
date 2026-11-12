<?php

namespace Native\Asgard;

use Illuminate\Support\ServiceProvider;
use Native\Asgard\Console\PushCommand;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\StreamClock;

class AsgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/asgard.php', 'asgard');

        $this->app->singleton(ComponentRegistry::class, fn ($app) => new ComponentRegistry(
            $app['config']->get('asgard.components', [])
        ));

        $this->app->singleton(PushVersions::class, fn ($app) => new PushVersions(
            $app['cache'],
            $app['config']->get('asgard.stream.store'),
        ));

        $this->app->singleton(StreamClock::class);
        $this->app->singleton(Asgard::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/asgard.php' => config_path('asgard.php'),
        ], 'asgard-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PushCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/asgard.php');
        }
    }
}
