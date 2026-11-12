<?php

namespace Native\Asgard\Tests\Fixtures;

use Closure;
use Native\Asgard\Support\StreamClock;

/** Moves time forward on sleep() without sleeping, and runs hooks on given sleeps. */
class FakeClock extends StreamClock
{
    public float $time = 1000.0;

    public int $sleeps = 0;

    /** @var array<int, Closure> sleep number => hook run after that sleep */
    public array $onSleep = [];

    public ?int $abortAfter = null;

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(int $milliseconds): void
    {
        $this->sleeps++;
        $this->time += $milliseconds / 1000;

        if (isset($this->onSleep[$this->sleeps])) {
            ($this->onSleep[$this->sleeps])();
        }
    }

    public function aborted(): bool
    {
        return $this->abortAfter !== null && $this->sleeps >= $this->abortAfter;
    }
}
