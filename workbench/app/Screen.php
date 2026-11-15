<?php

namespace Workbench\App;

use Native\Asgard\Attributes\Screen as ScreenRoute;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

/** conformance.screen from conformance/FIXTURES.md. */
#[ScreenRoute('/conformance/{id}', name: 'conformance.screen')]
class Screen extends ServerScreen
{
    public int $id = 0;

    public function render(): Node
    {
        return Node::text("Item {$this->id}");
    }

    public function chrome(): Chrome
    {
        return Chrome::make()->navBar(NavBar::make()->title("Item {$this->id}")->back());
    }
}
