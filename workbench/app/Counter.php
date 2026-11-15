<?php

namespace Workbench\App;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\Facades\Asgard;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** conformance.counter from conformance/FIXTURES.md in the protocol repo. */
class Counter extends ServerComponent
{
    public int $count = 0;

    public string $label = 'Counter';

    public ?int $user_id = null;

    public ?array $attachment = null;

    #[Region('count')]
    public function countRegion(): Node
    {
        return Node::text("Count: {$this->count}")->key('count');
    }

    #[Region('label')]
    public function labelRegion(): Node
    {
        return Node::text($this->label);
    }

    #[Region('big')]
    public function big(): array
    {
        return self::rows();
    }

    #[Region('quiet', compress: false)]
    public function quiet(): array
    {
        return self::rows();
    }

    #[Region('fancy')]
    public function fancy(): Node
    {
        return Node::make('list_item')->props(['headline' => 'Fancy']);
    }

    #[Region('feed')]
    public function feed(): array
    {
        $children = array_map(
            fn (array $item) => ['type' => 'text', 'key' => (string) $item['id'], 'props' => ['text' => $item['text']]],
            Cache::get('conformance.feed', []),
        );

        return ['type' => 'column', 'key' => 'items', 'children' => $children];
    }

    #[Region('shared', shared: true)]
    public function shared(): Node
    {
        return Node::text("Shared {$this->label}");
    }

    #[ServerAction]
    public function increment(int $by): void
    {
        $this->count += $by;
    }

    #[ServerAction]
    public function rename(string $label): void
    {
        $this->label = $label;
    }

    #[ServerAction]
    public function redraw(): void
    {
        $this->count++;
        $this->refresh('label');
        $this->refresh('conformance.other', 'note');
    }

    #[ServerAction]
    public function setUser(int $id): void
    {
        $this->user_id = $id;
    }

    #[ServerAction]
    public function ping(): void
    {
        Asgard::push('conformance.counter', 'count');
    }

    #[ServerAction]
    public function post(string $text): void
    {
        $id = Cache::increment('conformance.feed.id');
        $items = Cache::get('conformance.feed', []);
        array_unshift($items, ['id' => $id, 'text' => $text]);
        Cache::forever('conformance.feed', array_slice($items, 0, 50));
        Asgard::push('conformance.counter', 'feed');
    }

    #[ServerAction]
    public function shout(): void
    {
        Asgard::push('conformance.counter', 'shared');
    }

    public function reset(): void
    {
        $this->count = 0;
    }

    #[Upload(maxSize: 1024, mimes: ['text/plain'])]
    public function attach(UploadedFile $file, string $caption): void
    {
        $bytes = file_get_contents($file->getPathname());
        $this->attachment = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'caption' => $caption];
    }

    private static function rows(): array
    {
        $children = [];
        for ($i = 1; $i <= 40; $i++) {
            $children[] = ['type' => 'text', 'key' => $i, 'props' => ['text' => "Row {$i} of the conformance list"]];
        }

        return ['type' => 'column', 'children' => $children];
    }
}
