<?php

namespace Native\Asgard\Support;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use InvalidArgumentException;

/**
 * Channel names for targeted pushes. A stream joins channels through its
 * component's channels(), and Asgard::to() pushes to them.
 *
 * A channel is a string of 1 to 200 characters with no control characters,
 * or anything Laravel broadcasting accepts as a channel: an Eloquent model
 * (or any HasBroadcastChannel) is named by broadcastChannel(), so user 5 is
 * `App.Models.User.5`.
 */
final class Channels
{
    public const MAX = 16;

    public const MAX_LENGTH = 200;

    /** The channel's name, or an InvalidArgumentException saying why not. */
    public static function name(mixed $channel): string
    {
        if ($channel instanceof HasBroadcastChannel) {
            $channel = $channel->broadcastChannel();
        }

        if (! is_string($channel)) {
            throw new InvalidArgumentException('An Asgard channel must be a string or a model, '.get_debug_type($channel).' given.');
        }

        if ($channel === '' || strlen($channel) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('An Asgard channel must be 1 to '.self::MAX_LENGTH.' bytes long.');
        }

        if (! mb_check_encoding($channel, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $channel)) {
            throw new InvalidArgumentException("Asgard channel [{$channel}] must be UTF-8 with no control characters.");
        }

        return $channel;
    }

    /**
     * Every channel named, flattened, checked and without repeats. Throws on
     * the first bad one.
     *
     * @return list<string>
     */
    public static function names(iterable $channels): array
    {
        $names = [];
        foreach ($channels as $channel) {
            if (is_iterable($channel)) {
                array_push($names, ...self::names($channel));

                continue;
            }

            $names[] = self::name($channel);
        }

        return array_values(array_unique($names));
    }
}
