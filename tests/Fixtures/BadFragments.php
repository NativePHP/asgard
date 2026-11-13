<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class BadFragments extends ServerComponent
{
    public string $type = 'top_bar';

    #[Region('denied')]
    public function denied(): Node
    {
        return Node::column(Node::make($this->type));
    }

    #[Region('props')]
    public function props(): array
    {
        return [
            'type' => 'button',
            'props' => ['label' => 'Go', '_press' => 'nuke', 'native-key' => 'x', 'native:id' => 'y', '_navigate' => '/admin'],
            'on_press' => ['call' => 'go', 'args' => [1]],
        ];
    }

    #[Region('garbage')]
    public function garbage(): string
    {
        return 'not a node';
    }

    #[Region('shared_garbage', shared: true)]
    public function sharedGarbage(): string
    {
        return 'not a node';
    }

    public string $src = '';

    #[Region('image')]
    public function image(): Node
    {
        return Node::make('image')->prop('src', $this->src);
    }

    public string|int|bool|array|null $callback = null;

    #[Region('callback')]
    public function callback(): array
    {
        return ['type' => 'button', 'on_press' => $this->callback];
    }
}
