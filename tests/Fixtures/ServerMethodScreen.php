<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\Server;
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

    public mixed $anything = null;

    #[Server]
    public function refreshChart(string $range): void
    {
        static::$calls[] = 'refreshChart';
        $this->range = $range;
        $this->chart = [$range, $this->platform()];
        // Set to what it already holds, so it is not in the delta.
        $this->loading = $this->loading;
    }

    #[Server]
    public function rollDie(): void
    {
        static::$calls[] = 'rollDie';
        $this->roll = 4;
    }

    #[Server]
    public function upgrade(): void
    {
        static::$calls[] = 'upgrade';
        $this->plan = 'pro';
    }

    #[Server]
    public function fill(): void
    {
        static::$calls[] = 'fill';
        $this->filled = 3;
    }

    #[Server]
    public function sameAgain(): void
    {
        static::$calls[] = 'sameAgain';
        $this->chart = array_values($this->chart);
    }

    #[Server]
    public function leaveAnObject(): void
    {
        static::$calls[] = 'leaveAnObject';
        $this->anything = new \stdClass;
    }

    #[Server]
    public function leaveNan(): void
    {
        static::$calls[] = 'leaveNan';
        $this->anything = [NAN];
    }

    #[Server]
    public function needsTwo(int $a, int $b): void
    {
        static::$calls[] = 'needsTwo';
        $this->roll = $a + $b;
    }

    #[Action]
    public function actionOnly(): void
    {
        static::$calls[] = 'actionOnly';
    }

    public function unmarked(): void
    {
        static::$calls[] = 'unmarked';
    }

    #[Server]
    #[Region('chart')]
    public function chartRegion(): Node
    {
        static::$calls[] = 'chartRegion';

        return Node::text('chart');
    }

    #[Server]
    public static function staticServer(): void
    {
        static::$calls[] = 'staticServer';
    }

    #[Server]
    protected function protectedServer(): void
    {
        static::$calls[] = 'protectedServer';
    }

    #[Server]
    private function privateServer(): void
    {
        static::$calls[] = 'privateServer';
    }

    #[Server]
    public function withObject(object $thing): void
    {
        static::$calls[] = 'withObject';
    }
}
