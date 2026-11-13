<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as the server half of a
 * `#[Server]` method. The device's component has a method of the same
 * name, also marked `#[Server]`, whose body runs on the device first.
 *
 * It is the allowlist. Only methods carrying it, declared on the
 * component class itself, can ever be run by a call request, and an
 * `#[Action]` does not count as one.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Server {}
