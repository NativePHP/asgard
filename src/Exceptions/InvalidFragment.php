<?php

namespace Native\Asgard\Exceptions;

use RuntimeException;

/** A region returned something that is not a valid fragment. Rendered as 500. */
class InvalidFragment extends RuntimeException {}
