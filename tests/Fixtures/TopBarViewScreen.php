<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\ServerScreen;

/** Chrome tags in a screen's Blade body, or in a chrome view. Both are refused. */
#[Screen('/top-bar-view/{where}')]
class TopBarViewScreen extends ServerScreen
{
    public string $where = 'body';

    public function render(): View
    {
        return view($this->where === 'body' ? 'screens.with-top-bar' : 'regions.partial', ['label' => 'ok']);
    }

    public function chrome(): Chrome
    {
        return Chrome::make()->native()->bottomBar(view($this->where === 'bar' ? 'screens.with-top-bar' : 'screens.promo-bar'));
    }
}
