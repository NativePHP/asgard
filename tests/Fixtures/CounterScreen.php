<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class CounterScreen extends ServerComponent
{
    public int $count = 0;

    /** Renders mutate the instance, so reusing one would show up as a higher count. */
    public static int $renders = 0;

    #[Region('tick', shared: true)]
    public function tick(): Node
    {
        static::$renders++;
        $this->count++;

        return Node::text("Count: {$this->count}");
    }
}
