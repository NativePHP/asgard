<?php

// Screens that registration must refuse. Loaded by ScreenTest, not autoloaded.

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

#[Screen('/with-region')]
class RegionScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('body');
    }

    #[Region('extra')]
    public function extra(): Node
    {
        return Node::text('extra');
    }
}

class NoAttributeScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('body');
    }
}

#[Screen('/promos/{id}')]
class ClashingScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('clash');
    }
}

#[Screen('/a/{b?}/c')]
class BadUriScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('bad');
    }
}
