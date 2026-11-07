<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class HomeScreen extends ServerComponent
{
    public static array $calls = [];

    public string $name = 'friend';

    public int $count = 0;

    public ?float $ratio = null;

    public array $tags = [];

    #[Locked]
    public string $role = 'guest';

    public $untyped = 'u';

    #[Region('greeting')]
    public function greeting(): Node
    {
        return Node::column(
            Node::text("Hello, {$this->name}")->key('hello'),
            Node::text("Role: {$this->role}"),
            Node::text("Count: {$this->count}"),
            Node::text('Platform: '.$this->platform()),
        )->class('p-4 gap-2');
    }

    #[Region('list')]
    public function list(): array
    {
        return [Node::text('one'), ['type' => 'text', 'props' => ['text' => 'two']]];
    }

    #[Region('tags')]
    public function tagsRegion(): Node
    {
        return Node::row(...array_map(fn ($t) => Node::text((string) $t), $this->tags));
    }

    #[Region('static')]
    public static function staticRegion(): Node
    {
        static::$calls[] = 'staticRegion';

        return Node::text('static');
    }

    public function secret(): Node
    {
        static::$calls[] = 'secret';

        return Node::text('secret');
    }
}
