<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class ServerMethodScreen extends ServerComponent
{
    public static array $calls = [];

    public string $range = '7d';

    public array $chart = [];

    public bool $loading = false;

    public ?int $roll = null;

    #[Locked]
    public string $plan = 'free';

    public int $filled;

    public $untyped = 'u';

    public string $platform = '';

    public mixed $anything = null;

    #[ServerAction]
    public function refreshChart(string $range): void
    {
        static::$calls[] = 'refreshChart';
        $this->range = $range;
        $this->chart = [$range, $this->platform];
        // Set to what it already holds, so it is not in the delta.
        $this->loading = $this->loading;
    }

    #[ServerAction]
    public function rollDie(): void
    {
        static::$calls[] = 'rollDie';
        $this->roll = 4;
    }

    #[ServerAction]
    public function upgrade(): void
    {
        static::$calls[] = 'upgrade';
        $this->plan = 'pro';
    }

    #[ServerAction]
    public function fill(): void
    {
        static::$calls[] = 'fill';
        $this->filled = 3;
    }

    #[ServerAction]
    public function sameAgain(): void
    {
        static::$calls[] = 'sameAgain';
        $this->chart = array_values($this->chart);
    }

    #[ServerAction]
    public function leaveAnObject(): void
    {
        static::$calls[] = 'leaveAnObject';
        $this->anything = new \stdClass;
    }

    #[ServerAction]
    public function leaveNan(): void
    {
        static::$calls[] = 'leaveNan';
        $this->anything = [NAN];
    }

    /** Rolls, then asks for this component's chart and a hint for another. */
    #[ServerAction]
    public function rollAndRedraw(string ...$regions): void
    {
        static::$calls[] = 'rollAndRedraw';
        $this->roll = 6;
        $this->refresh(...$regions);
    }

    #[ServerAction]
    public function hintOther(string $target, string ...$region): void
    {
        static::$calls[] = 'hintOther';
        $this->refresh($target, ...$region);
    }

    #[Region('rolled')]
    public function rolledRegion(): Node
    {
        return Node::text('Rolled '.($this->roll ?? 'nothing').' on '.$this->platform.' in '.$this->region());
    }

    #[ServerAction]
    public function needsTwo(int $a, int $b): void
    {
        static::$calls[] = 'needsTwo';
        $this->roll = $a + $b;
    }

    public function unmarked(): void
    {
        static::$calls[] = 'unmarked';
    }

    #[ServerAction]
    #[Region('chart')]
    public function chartRegion(): Node
    {
        static::$calls[] = 'chartRegion';

        return Node::text('chart');
    }

    #[ServerAction]
    public static function staticServer(): void
    {
        static::$calls[] = 'staticServer';
    }

    #[ServerAction]
    protected function protectedServer(): void
    {
        static::$calls[] = 'protectedServer';
    }

    #[ServerAction]
    private function privateServer(): void
    {
        static::$calls[] = 'privateServer';
    }

    #[ServerAction]
    public function withObject(object $thing): void
    {
        static::$calls[] = 'withObject';
    }
}
