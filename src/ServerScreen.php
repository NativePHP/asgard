<?php

namespace Native\Asgard;

use Illuminate\Contracts\View\View;
use Native\Asgard\Chrome\Chrome;

/**
 * A whole screen the server owns (Asgard protocol §12.1).
 *
 *     #[Screen('/promos/{id}')]
 *     class PromoScreen extends ServerScreen
 *     {
 *         public int $id;
 *
 *         public function render(): Node
 *         {
 *             return Node::column(Node::text("Promo {$this->id}"));
 *         }
 *
 *         public function chrome(): Chrome
 *         {
 *             return Chrome::make()->navBar(NavBar::make()->title('Promo')->back());
 *         }
 *     }
 *
 * The device opens it in a generic screen whose body is one region, named
 * `screen`. Route parameters and the query string arrive as state, so
 * public typed properties named after them are filled in. Numeric and
 * boolean strings are converted to match the property's type, because a
 * route parameter is always a string on the wire.
 *
 * #[ServerAction] methods work as they do on any component, and so does
 * Asgard::push(). #[Region] methods do not: a screen has exactly one body.
 */
abstract class ServerScreen extends ServerComponent
{
    /** The region name the screen's body travels under. */
    public const REGION = 'screen';

    /**
     * The screen's body: a Node, a node array, a list of them, or a Blade
     * view (`return view('screens.promo')`), which is compiled on the
     * server like a region view. Chrome tags such as `<top-bar>` are
     * refused in the body; chrome comes from chrome() only.
     *
     * @return Node|array<mixed>|View
     */
    abstract public function render(): mixed;

    /**
     * The screen's chrome: nav bar, tab bar, bottom bar and the rest. Null
     * means none. Chrome never travels inside the body; it is its own part
     * of the answer and is checked on its own.
     *
     * @return Chrome|array<string, mixed>|null
     */
    public function chrome(): Chrome|array|null
    {
        return null;
    }
}
