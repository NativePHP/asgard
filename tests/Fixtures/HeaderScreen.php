<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** Reads plain HTTP headers the app sends, the way Laravel always does. */
class HeaderScreen extends ServerComponent
{
    #[Region('version')]
    public function version(): Node
    {
        return Node::text(request()->header('X-App-Version', 'none').' '.request()->header('Accept-Language', 'none'));
    }
}
