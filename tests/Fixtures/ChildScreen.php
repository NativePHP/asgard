<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Node;

class ChildScreen extends ParentScreen
{
    use HelperTrait;

    public int $count = 0;

    #[Region('count')]
    public function countRegion(): Node
    {
        return Node::text("Count: {$this->count}");
    }

    #[ServerAction]
    public function bump(): void
    {
        static::$calls[] = 'bump';
        $this->count++;
    }
}
