<?php

namespace Native\Asgard\Support;

/**
 * Time, sleeping and disconnect checks for the stream loop. Bound in the
 * container so tests can swap in one that never really sleeps.
 */
class StreamClock
{
    /** Seconds, with microseconds. */
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }
}
