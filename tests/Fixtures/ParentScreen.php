<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\ServerAction;
use Native\Asgard\ServerComponent;

abstract class ParentScreen extends ServerComponent
{
    public static array $calls = [];

    #[ServerAction]
    public function inherited(): void
    {
        static::$calls[] = 'inherited';
    }
}
