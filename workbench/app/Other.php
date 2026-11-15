<?php

namespace Workbench\App;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** conformance.other from conformance/FIXTURES.md. */
class Other extends ServerComponent
{
    #[Region('note')]
    public function note(): Node
    {
        return Node::text('Note');
    }
}
