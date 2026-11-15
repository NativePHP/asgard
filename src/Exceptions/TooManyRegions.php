<?php

namespace Native\Asgard\Exceptions;

/** A request names more regions than asgard.max_regions allows. Rendered as 422 too_many_regions. */
class TooManyRegions extends InvalidRequest {}
