<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\ServerScreen;

#[Screen('/blade-promos/{id}', name: 'blade_promos.show', shared: true)]
class BladePromoScreen extends ServerScreen
{
    public int $id = 0;

    public int $likes = 0;

    public function render(): View
    {
        return view('screens.promo');
    }

    public function chrome(): Chrome
    {
        return Chrome::make()
            ->native()
            ->navBar(NavBar::make()->title("Promo #{$this->id}"))
            ->bottomBar(view('screens.promo-bar'))
            ->tabAccessory(view('regions.partial', ['label' => "Promo {$this->id}"]));
    }

    #[ServerAction]
    public function like(): void
    {
        $this->likes++;
    }
}
