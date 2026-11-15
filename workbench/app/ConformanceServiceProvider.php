<?php

namespace Workbench\App;

use Illuminate\Support\ServiceProvider;
use Native\Asgard\Facades\Asgard;

class ConformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'asgard.key' => env('ASGARD_KEY', 'conformance-key'),
            'asgard.middleware' => [RejectBearer::class],
            'cache.default' => 'file',
        ]);
    }

    public function boot(): void
    {
        Asgard::component('conformance.counter', Counter::class);
        Asgard::component('conformance.other', Other::class);
        Asgard::screen(Screen::class);
    }
}
