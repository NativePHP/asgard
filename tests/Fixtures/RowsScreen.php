<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** Regions whose size is set by state, one compressed and one opted out. */
class RowsScreen extends ServerComponent
{
    public int $count = 200;

    #[Region('rows')]
    public function rows(): array
    {
        return $this->build('Row');
    }

    #[Region('private', compress: false)]
    public function private(): array
    {
        return $this->build('Account');
    }

    #[Action]
    public function more(): void
    {
        $this->count += 10;
    }

    protected function build(string $label): array
    {
        return array_map(
            fn (int $i) => Node::row(Node::text("{$label} {$i}"), Node::text('Detail for '.strtolower($label)." {$i}"))->key("r{$i}")->class('p-2 gap-2'),
            $this->count > 0 ? range(1, $this->count) : [],
        );
    }
}
