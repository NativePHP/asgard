<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** One region holding a keyed list, for patches. */
class FeedScreen extends ServerComponent
{
    /** @var list<string> */
    public static array $items = ['a', 'b'];

    /** @var list<int> */
    public static array $liked = [];

    #[Region('feed')]
    public function feed(): Node
    {
        return Node::column(
            Node::text('Feed')->key('title'),
            Node::column(...array_map(fn (string $item) => Node::text($item)->key($item), static::$items))->key('items'),
        );
    }

    #[ServerAction]
    public function like(int $id): void
    {
        static::$liked[] = $id;
    }
}
