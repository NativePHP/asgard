<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as the renderer for a region.
 * Only methods carrying this attribute can ever be reached from the wire.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Region
{
    public function __construct(public string $name) {}
}
