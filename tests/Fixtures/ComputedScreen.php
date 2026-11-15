<?php

namespace Native\Asgard\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Computed;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;
use RuntimeException;

class ComputedScreen extends ServerComponent
{
    /** What total() gives instead of the sum: 'object', 'throw', 'invalid'. */
    public static ?string $mode = null;

    public static int $totals = 0;

    public array $items = [];

    public int $added = 0;

    public string $platform = '';

    #[Computed]
    public function total(): mixed
    {
        static::$totals++;

        return match (static::$mode) {
            'object' => new \stdClass,
            'throw' => throw new RuntimeException('total broke'),
            'invalid' => throw new InvalidRequest('total needs items'),
            default => array_sum($this->items),
        };
    }

    #[Computed]
    public function count(int $offset = 0): int
    {
        return count($this->items) + $offset;
    }

    #[ServerAction]
    public function add(int $item): void
    {
        $this->items[] = $item;
        $this->added++;
    }

    #[ServerAction]
    public function nothing(): void {}

    #[Upload]
    public function attach(UploadedFile $file): void
    {
        $this->added++;
    }

    #[Region('summary')]
    public function summary(): Node
    {
        return Node::text('Total '.$this->total());
    }

    public static function serverVersion(): string
    {
        return '2.1.0';
    }

    public static function headerValue(): ?string
    {
        return 'from-callable';
    }

    public static function nothingToSay(): ?string
    {
        return null;
    }
}
