<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;
use Native\Asgard\ServerComponent;

abstract class ParentScreen extends ServerComponent
{
    public static array $calls = [];

    #[Action]
    public function inherited(): void
    {
        static::$calls[] = 'inherited';
    }
}
