<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * A public property the client may never write. It keeps whatever value
 * the server gives it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Locked {}
