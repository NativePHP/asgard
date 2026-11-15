<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class BladeScreen extends ServerComponent
{
    public string $name = 'friend';

    public int $count = 0;

    #[Region('stats', shared: true)]
    public function stats(): View
    {
        return view('regions.stats', ['items' => ['a', 'b']]);
    }

    /** The Node tree regions/stats.blade.php should build. */
    #[Region('nodes')]
    public function nodes(): Node
    {
        return Node::column(
            Node::text("Hello, {$this->name}")->class('font-bold'),
            Node::text("Count: {$this->count}"),
            Node::row(Node::text('a'))->key(0),
            Node::row(Node::text('b'))->key(1),
            Node::button('Bump')->onPress('bump', 2),
            Node::button('Party')->onPressClient('celebrate', $this->name),
            Node::divider(),
        )->class('p-4 gap-2')->key('stats');
    }

    #[Region('many')]
    public function many(): View
    {
        return view('regions.many');
    }

    #[Region('chrome')]
    public function chrome(): View
    {
        return view('regions.chrome');
    }

    #[ServerAction]
    public function bump(int $by = 1): void
    {
        $this->count += $by;
    }
}
