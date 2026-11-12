<?php

namespace Native\Asgard\Tests\Fixtures;

use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavAction;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\Chrome\Tab;
use Native\Asgard\Chrome\TabBar;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

#[Screen('/promos/{id}', name: 'promos.show')]
class PromoScreen extends ServerScreen
{
    public static array $calls = [];

    public int $id = 0;

    public bool $preview = false;

    public ?string $ref = null;

    public int $likes = 0;

    public function render(): Node
    {
        static::$calls[] = 'render';

        return Node::column(
            Node::text("Promo {$this->id}")->key('title'),
            Node::text("Likes {$this->likes}"),
            Node::button('More')->onPressNavigate('/promos/'.($this->id + 1), transition: 'fade'),
            Node::button('Close')->onPressBack(),
        );
    }

    public function chrome(): Chrome
    {
        return Chrome::make()
            ->native()
            ->navBar(NavBar::make()
                ->title("Promo #{$this->id}")
                ->back()
                ->action(NavAction::make('like')->icon('heart')->a11yLabel('Like')->onPress('like'))
                ->action(NavAction::make('more')->icon('ellipsis')->items([
                    NavAction::make('home')->label('Home')->onPressNavigate('/', replace: true),
                    NavAction::divider(),
                    NavAction::make('report')->label('Report')->destructive()->onPressClient('report'),
                ])))
            ->tabBar(TabBar::make()->hidden()->add(Tab::link('Home', '/', icon: 'home')));
    }

    #[Action]
    public function like(int $by = 1): void
    {
        static::$calls[] = 'like';
        $this->likes += $by;
    }
}
