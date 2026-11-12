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
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Region
{
    public function __construct(
        public string $name,
        public bool $compress = true,
    ) {}
}
