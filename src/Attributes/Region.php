<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as the renderer for a region.
 * Only methods carrying this attribute can ever be reached from the wire.
 *
 * Fragments are gzipped before they are encrypted. Pass `compress: false`
 * for a region whose fragment mixes a secret (a token, an account number)
 * with text someone else can choose (a search term, a name they set). A
 * compressed body of that shape leaks the secret through its length, the
 * way BREACH does. Protocol §7.4.
 *
 * `shared: true` marks a region that renders the same for everyone with the
 * same state. When a push moves it, the push stream sends its fragment
 * inline, rendered once for every device with that state, instead of a
 * signal that makes each device fetch it (Asgard protocol §8.1). On the stream it
 * renders with no authenticated user, so it must not depend on the user or
 * hold secrets. That also makes compressing it safe. Leave it off when in
 * doubt: a region that is not shared costs a round trip, never a leak.
 *
 * `maxAge` is the longest, in seconds, a client may keep showing a cached
 * copy of this region without the server rendering it again. null uses
 * `asgard.cache.max_age`. 0 means the client never caches it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Region
{
    public function __construct(
        public string $name,
        public bool $compress = true,
        public bool $shared = false,
        public ?int $maxAge = null,
    ) {}
}
