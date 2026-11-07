<?php

namespace Native\Asgard\Exceptions;

use RuntimeException;

/** The request body or state is malformed. Rendered as 422. */
class InvalidRequest extends RuntimeException {}
