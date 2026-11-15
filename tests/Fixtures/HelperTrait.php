<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\ServerAction;

trait HelperTrait
{
    public function fromTrait(): void
    {
        ChildScreen::$calls[] = 'fromTrait';
    }

    #[ServerAction]
    public function markedInTrait(): void
    {
        ChildScreen::$calls[] = 'markedInTrait';
    }
}
