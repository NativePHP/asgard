<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as one the device may run on
 * the server. It is the one allowlist for both ways in: a tap or press
 * inside a fragment (the render endpoint's `action`) and the server half
 * of a device's own `#[Server]` method (the call endpoint).
 *
 * Only methods carrying it, declared on the component class itself (or a
 * trait it uses), can ever be run by either request. `#[Upload]` methods
 * are a separate allowlist.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ServerAction {}
