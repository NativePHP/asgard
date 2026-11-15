<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Screen;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

#[Screen('/cached-promos/{id}', maxAge: 90)]
class CachedPromoScreen extends ServerScreen
{
    public int $id = 0;

    public function render(): Node
    {
        return Node::text("Promo {$this->id}");
    }
}
