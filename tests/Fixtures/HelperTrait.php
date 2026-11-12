<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;

trait HelperTrait
{
    public function fromTrait(): void
    {
        ChildScreen::$calls[] = 'fromTrait';
    }

    #[Action]
    public function markedInTrait(): void
    {
        ChildScreen::$calls[] = 'markedInTrait';
    }
}
