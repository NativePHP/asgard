<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;
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

    #[Action]
    public function shuffle(int $by = 1, string $suffix = ''): void
    {
        static::$calls[] = 'shuffle';
        $this->count += $by;
        $this->name .= $suffix;
    }

    /** Bumps the count, then asks for these regions of this component. */
    #[Action]
    public function redraw(string ...$regions): void
    {
        static::$calls[] = 'redraw';
        $this->count++;
        $this->tags = ['a', 'b'];
        $this->refresh(...$regions);
    }

    /** Names another component, or one of its regions, for the device to fetch. */
    #[Action]
    public function hint(string $target, string ...$region): void
    {
        static::$calls[] = 'hint';
        $this->refresh($target, ...$region);
    }

    /** Several refresh() calls in one action. */
    #[Action]
    public function hintMany(): void
    {
        $this->refresh(ChildScreen::class, 'count');
        $this->refresh('app.native.screens.child');
        $this->refresh('app.native.screens.channel', 'title');
        $this->refresh('list');
        $this->refresh('list', 'tags');
    }

    #[Action]
    public function promote(): void
    {
        static::$calls[] = 'promote';
        $this->role = 'admin';
    }

    #[Action]
    public function needsTwo(int $a, int $b): void
    {
        static::$calls[] = 'needsTwo';
        $this->count = $a + $b;
    }

    #[Action]
    public function sum(int ...$numbers): void
    {
        static::$calls[] = 'sum';
        $this->count = array_sum($numbers);
    }

    #[Action]
    public function lists(array ...$lists): void
    {
        static::$calls[] = 'lists';
    }

    #[Action]
    public function anything(mixed $value, $untyped = null, ?float $ratio = null, int|string|null $id = null): void
    {
        static::$calls[] = 'anything';
        $this->tags = [$value, $untyped, $ratio, $id];
    }

    public function unmarked(): void
    {
        static::$calls[] = 'unmarked';
    }

    #[Action]
    #[Region('both')]
    public function both(): Node
    {
        static::$calls[] = 'both';

        return Node::text('both');
    }

    #[Action]
    public static function staticAction(): void
    {
        static::$calls[] = 'staticAction';
    }

    #[Action]
    protected function protectedAction(): void
    {
        static::$calls[] = 'protectedAction';
    }

    #[Action]
    private function privateAction(): void
    {
        static::$calls[] = 'privateAction';
    }

    #[Action]
    public function __invoke(): void
    {
        static::$calls[] = '__invoke';
    }

    #[Action]
    public function __call(string $name, array $arguments): mixed
    {
        static::$calls[] = '__call';

        return null;
    }

    #[Action]
    public function withModel(HomeScreen $other): void
    {
        static::$calls[] = 'withModel';
    }

    #[Action]
    public function withInterface(\Countable $things): void
    {
        static::$calls[] = 'withInterface';
    }

    #[Action]
    public function withSelf(?self $other = null): void
    {
        static::$calls[] = 'withSelf';
    }

    #[Action]
    public function withCallable(callable $fn): void
    {
        static::$calls[] = 'withCallable';
    }

    #[Action]
    public function withObject(object $thing): void
    {
        static::$calls[] = 'withObject';
    }

    #[Action]
    public function withReference(int &$count): void
    {
        static::$calls[] = 'withReference';
    }

    #[Action]
    public function withUnion(int|\Countable $thing): void
    {
        static::$calls[] = 'withUnion';
    }
}
