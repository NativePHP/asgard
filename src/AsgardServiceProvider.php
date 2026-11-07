<?php

namespace Native\Asgard;

use Illuminate\Support\ServiceProvider;

class AsgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/asgard.php', 'asgard');

        $this->app->singleton(ComponentRegistry::class, fn ($app) => new ComponentRegistry(
            $app['config']->get('asgard.components', [])
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/asgard.php' => config_path('asgard.php'),
        ], 'asgard-config');

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/asgard.php');
        }
    }
}
