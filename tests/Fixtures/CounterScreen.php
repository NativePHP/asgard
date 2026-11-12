<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class CounterScreen extends ServerComponent
{
    public int $count = 0;

    /** Renders mutate the instance, so reusing one would show up as a higher count. */
    #[Region('tick')]
    public function tick(): Node
    {
        $this->count++;

        return Node::text("Count: {$this->count}");
    }
}
