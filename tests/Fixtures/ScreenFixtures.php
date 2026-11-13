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

#[Screen('/titled/{id}', title: 'Order {order}')]
class UnknownPlaceholderTitleScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('title');
    }
}

#[Screen('/titled/{id}', title: "Line one\nLine two")]
class ControlCharacterTitleScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('title');
    }
}

#[Screen('/titled/{id}', title: '   ')]
class BlankTitleScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('title');
    }
}

#[Screen('/titled/{id}', title: 'A title far too long for any nav bar, which goes on and on well past the hundred character limit set here')]
class LongTitleScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('title');
    }
}

#[Screen('/titled/{id?}', title: 'Ticket {id}')]
class OptionalParamTitleScreen extends ServerScreen
{
    public function render(): Node
    {
        return Node::text('title');
    }
}
