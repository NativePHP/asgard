<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\LazyCollection;
use Native\Asgard\Attributes\Computed;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\ClientCache;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** State that changes in every place the server can change it. */
class StateScreen extends ServerComponent
{
    public static array $calls = [];

    public string $name = '';

    public int $count = 0;

    public int $views = 0;

    public string $checked = '';

    public string $platform = '';

    /** Untyped, so never hydrated. The `lazy` region fills it with view data. */
    public $lines = [];

    #[Computed]
    public function label(): string
    {
        return "{$this->name} ({$this->count})";
    }

    /** Runs straight after hydration, before anything renders. */
    protected function checkClientCache(string $region, ClientCache $cache): void
    {
        $this->checked = 'yes';
    }

    #[Region('quiet')]
    public function quiet(): Node
    {
        static::$calls[] = 'quiet';

        return Node::text("Count {$this->count}");
    }

    /** Changes state while it renders. */
    #[Region('counted')]
    public function counted(): Node
    {
        static::$calls[] = 'counted';
        $this->views++;

        return Node::text("Views {$this->views}");
    }

    /** Changes a prop the device may send as read-only. */
    #[Region('meddles')]
    public function meddles(): Node
    {
        $this->platform = 'server';

        return Node::text('meddled');
    }

    /** Puts a value no answer can carry in a public prop while rendering. */
    #[Region('lazy')]
    public function lazy(): Node
    {
        $this->lines = LazyCollection::make(fn () => yield 'a');

        return Node::text('lazy');
    }

    #[Region('stats')]
    public function stats(): Node
    {
        static::$calls[] = 'stats';

        return Node::text("Stats {$this->count}");
    }

    #[Region('list')]
    public function list(): Node
    {
        static::$calls[] = 'list';

        return Node::text("List {$this->count}");
    }

    #[ServerAction]
    public function bump(string ...$refresh): void
    {
        static::$calls[] = 'bump';
        $this->count++;

        if ($refresh !== []) {
            $this->refresh(...$refresh);
        }
    }

    #[ServerAction]
    public function nothing(): void {}

    #[ServerAction]
    public function retarget(): void
    {
        $this->platform = 'android';
        $this->count++;
    }

    #[Upload]
    public function attach(UploadedFile $file): void
    {
        static::$calls[] = 'attach';
        $this->count += 10;
    }
}
