<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

/** Joins promo.{promo} from state, and the user's channels when signed in. */
class ChannelScreen extends ServerComponent
{
    /** Extra channels() entries, for tests of bad answers. */
    public static array $extra = [];

    public int $promo = 0;

    public function channels(): array
    {
        $channels = ["promo.{$this->promo}"];

        if (auth()->check()) {
            $channels[] = 'user.'.auth()->id();
            $channels[] = auth()->user();
        }

        return [...$channels, ...static::$extra];
    }

    #[Region('title')]
    public function title(): Node
    {
        return Node::text("Promo {$this->promo} for ".(auth()->id() ?? 'guest'));
    }

    #[Region('body')]
    public function body(): Node
    {
        return Node::text('Body');
    }
}
