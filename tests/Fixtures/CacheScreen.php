<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\ClientCache;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** Regions for the region cache tests. Every region method logs itself in $calls. */
class CacheScreen extends ServerComponent
{
    public static array $calls = [];

    /** Copies made before this (Unix ms) are out of date, as checkClientCache() sees it. */
    public static ?int $changedAt = null;

    /** What checkClientCache() was handed, by region. */
    public static array $checked = [];

    /** What clientCache() gave inside the last action or call, by region. */
    public static array $seen = [];

    /** When true, checkClientCache() forces invalidation for any push fetch. */
    public static bool $pushWins = false;

    /** fetchedForPush() as the last region method saw it. */
    public static ?bool $push = null;

    public int $count = 0;

    #[Region('stats', maxAge: 60)]
    public function stats(): Node
    {
        static::$calls[] = 'stats';
        static::$push = $this->fetchedForPush();

        return Node::text("Stats {$this->count}");
    }

    #[Region('greeting')]
    public function greeting(): Node
    {
        static::$calls[] = 'greeting';

        return Node::text("Hello {$this->count}");
    }

    #[Region('never', maxAge: 0)]
    public function never(): Node
    {
        static::$calls[] = 'never';

        return Node::text('never');
    }

    /** Decides while rendering that every copy is out of date. */
    #[Region('late')]
    public function late(): Node
    {
        static::$calls[] = 'late';
        $this->forceCacheInvalidation();

        return Node::text('late');
    }

    #[Region('board', shared: true, maxAge: 30)]
    public function board(): Node
    {
        static::$calls[] = 'board';

        return Node::text("Board {$this->count}");
    }

    /** Changes data but leaves the cache alone. */
    #[ServerAction]
    public function bump(string ...$regions): void
    {
        $this->count++;
        $this->remember();

        if ($regions !== []) {
            $this->refresh(...$regions);
        }
    }

    /** Changes data and tells every client copy to go. */
    #[ServerAction]
    public function bumpAndForce(string ...$regions): void
    {
        $this->count++;
        $this->forceCacheInvalidation();

        if ($regions !== []) {
            $this->refresh(...$regions);
        }
    }

    #[ServerAction]
    public function serverBump(bool $force, string ...$regions): void
    {
        $this->count++;
        $this->remember();

        if ($force) {
            $this->forceCacheInvalidation();
        }

        if ($regions !== []) {
            $this->refresh(...$regions);
        }
    }

    #[Upload]
    public function attach(UploadedFile $file, bool $force, string ...$regions): void
    {
        $this->count++;

        if ($force) {
            $this->forceCacheInvalidation();
        }

        if ($regions !== []) {
            $this->refresh(...$regions);
        }
    }

    protected function checkClientCache(string $region, ClientCache $cache): void
    {
        static::$checked[$region] = $cache;

        if (static::$pushWins && $this->fetchedForPush()) {
            $this->forceCacheInvalidation();
        }

        if (static::$changedAt !== null && $cache->madeAt->getTimestampMs() < static::$changedAt) {
            $this->forceCacheInvalidation();
        }
    }

    private function remember(): void
    {
        foreach (['stats', 'greeting', 'nope'] as $region) {
            static::$seen[$region] = $this->clientCache($region);
        }
    }

    public static function reset(): void
    {
        static::$calls = [];
        static::$changedAt = null;
        static::$checked = [];
        static::$seen = [];
        static::$pushWins = false;
        static::$push = null;
    }
}
