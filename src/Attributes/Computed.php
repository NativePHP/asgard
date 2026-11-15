<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as a computed value. It is
 * worked out fresh each time a `#[Server]` call or an upload is answered,
 * and goes in the delta under the method's name, every time.
 *
 * It is read-only. A state key of the same name never writes anything,
 * and the method can never be run as a server action, an upload or a
 * region. It takes no required parameters, and a public prop
 * of the same name is refused.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Computed {}
