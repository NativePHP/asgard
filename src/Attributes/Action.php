<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Marks a public method on a ServerComponent as callable from a fragment
 * callback. Only methods carrying this attribute, declared on the component
 * class itself, can ever be run by an action request.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Action {}
