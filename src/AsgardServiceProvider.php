<?php

namespace Native\Asgard;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Native\Asgard\Blade\Elements;
use Native\Asgard\Blade\NativeTagPrecompiler;
use Native\Asgard\Blade\RegionCompiler;
use Native\Asgard\Blade\RegionEngine;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\Console\KeyCommand;
use Native\Asgard\Console\PushCommand;
use Native\Asgard\Http\MethodNotAllowed;
use Native\Asgard\Support\ConfiguredHeaders;
use Native\Asgard\Support\GuestGuard;
use Native\Asgard\Support\PushVersions;
use Native\Asgard\Support\SessionStore;
use Native\Asgard\Support\StreamClock;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class AsgardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/asgard.php', 'asgard');

        $this->app->singleton(ComponentRegistry::class, fn ($app) => new ComponentRegistry(
            $app['config']->get('asgard.components', [])
        ));

        $this->app->singleton(ScreenRegistry::class, fn ($app) => new ScreenRegistry(
            $app->make(ComponentRegistry::class),
            $app['config']->get('asgard.screens', []),
        ));

        // Screens are components too, so whatever looks a component up must
        // see them: registering the screens fills in the component map.
        $this->app->afterResolving(ComponentRegistry::class, fn ($registry, $app) => $app->make(ScreenRegistry::class));

        $this->app->singleton(PushVersions::class, fn ($app) => new PushVersions(
            $app['cache'],
            $app['config']->get('asgard.stream.store'),
        ));

        $this->app->singleton(SessionStore::class, fn ($app) => new SessionStore(
            $app['cache'],
            $app['config']->get('asgard.sessions.store'),
            (int) $app['config']->get('asgard.sessions.lifetime', 1800),
        ));

        $this->app->singleton(StreamClock::class);

        // The guard a stream renders shared regions under: nobody signed in.
        $this->app['config']->set('auth.guards.'.GuestGuard::NAME, ['driver' => GuestGuard::NAME]);
        $this->app->afterResolving('auth', fn ($auth) => $auth->extend(GuestGuard::NAME, fn () => new GuestGuard));

        // Region views get their own compiler, so the app's web views never
        // meet NativeTagPrecompiler. Built on first use, after the app has
        // registered its own directives.
        $this->app->singleton('asgard.blade.compiler', function ($app) {
            $config = $app['config'];

            $compiler = new RegionCompiler(
                $app['files'],
                $config->get('asgard.blade.compiled') ?: rtrim($config->get('view.compiled') ?: storage_path('framework/views'), '/').'/asgard',
                $config->get('view.relative_hash', false) ? $app->basePath() : '',
                $config->get('view.cache', true),
                $config->get('view.compiled_extension', 'php'),
                $config->get('view.check_cache_timestamps', true),
            );

            $compiler->inherit($app['blade.compiler']);
            $compiler->precompiler(new NativeTagPrecompiler(Elements::tags($config->get('asgard.blade.elements', []))));

            return $compiler;
        });

        $this->app->singleton(RegionEngine::class, fn ($app) => new RegionEngine($app['asgard.blade.compiler'], $app['files']));
        $this->app->singleton(RegionViews::class);
        $this->app->singleton(Asgard::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/asgard.php' => config_path('asgard.php'),
        ], 'asgard-config');

        if ($this->app->runningInConsole()) {
            $this->commands([KeyCommand::class, PushCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/asgard.php');
        }

        // A verb the routes do not list at all still gets the protocol's 405
        // body on an Asgard endpoint, not the framework's (Asgard protocol §7.3).
        // No route matched, so asgard.headers are added here.
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(fn (MethodNotAllowedHttpException $e, Request $request) => match (MethodNotAllowed::endpoint($request)) {
                    null => null,
                    'session' => ConfiguredHeaders::apply(MethodNotAllowed::response(MethodNotAllowed::SESSION_ALLOW)),
                    default => ConfiguredHeaders::apply(MethodNotAllowed::response(MethodNotAllowed::ALLOW)),
                });
            }
        });
    }
}
