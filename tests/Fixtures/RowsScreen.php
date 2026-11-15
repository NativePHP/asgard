<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** Regions whose size is set by state, one compressed and one opted out. */
class RowsScreen extends ServerComponent
{
    public int $count = 200;

    #[Region('rows', shared: true)]
    public function rows(): array
    {
        return $this->build('Row');
    }

    #[Region('private', compress: false, shared: true)]
    public function private(): array
    {
        return $this->build('Account');
    }

    #[ServerAction]
    public function more(): void
    {
        $this->count += 10;
    }

    /** Changes nothing, so its answer's delta is empty. */
    #[ServerAction]
    public function look(): void {}

    /** Grows, and redraws both regions in one answer. */
    #[ServerAction]
    public function grow(): void
    {
        $this->count += 10;
        $this->refresh('rows', 'private');
    }

    #[ServerAction]
    public function regrow(string ...$regions): void
    {
        $this->count += 10;

        if ($regions !== []) {
            $this->refresh(...$regions);
        }
    }

    protected function build(string $label): array
    {
        return array_map(
            fn (int $i) => Node::row(Node::text("{$label} {$i}"), Node::text('Detail for '.strtolower($label)." {$i}"))->key("r{$i}")->class('p-2 gap-2'),
            $this->count > 0 ? range(1, $this->count) : [],
        );
    }
}
