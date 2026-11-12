<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Screen;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

#[Screen('/bad-chrome')]
class BadChromeScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('body');
    }

    public function chrome(): array
    {
        return ['nav_bar' => ['title' => 'Hi', 'colour' => 'red']];
    }
}
