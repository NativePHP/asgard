<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Screen;
use Native\Asgard\ServerScreen;

#[Screen('/', compress: false)]
class BareScreen extends ServerScreen
{
    public array $tags = [];

    public float $ratio = 0.0;

    public function render(): array
    {
        return ['type' => 'text', 'props' => ['text' => str_repeat('x', 2000).json_encode([$this->tags, $this->ratio])]];
    }
}
