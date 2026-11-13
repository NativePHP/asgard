<?php

namespace Native\Asgard\Attributes;

use Attribute;

/**
 * Makes a ServerScreen a whole screen at a route of the app.
 *
 *     #[Screen('/promos/{id}')]
 *     class PromoScreen extends ServerScreen { ... }
 *
 * `uri` is a route pattern in Laravel's spelling: literal segments and
 * `{name}` parameters, with an optional `{name?}` last. The device opens
 * it like any of its own routes, but an app route always wins: a server
 * screen can add a route, never replace one.
 *
 * `name` is the screen's wire address. It defaults to the class name in
 * dot notation, the way client components are addressed (protocol §4).
 *
 * `compress: false` turns off gzip for the screen's answers, for the same
 * reason #[Region] has it (protocol §7.4). `shared: true` is #[Region]'s
 * too: the screen renders the same for everyone with the same state, so a
 * push sends it inline (protocol §8.1).
 *
 * `title` is a nav bar title the device shows the moment the screen opens,
 * before it has asked the server for anything:
 *
 *     #[Screen('/promos/{id}', title: 'Promo #{id}')]
 *
 * `{name}` placeholders are filled from the route parameters of the same
 * name, and every placeholder must be one. The title is plain text of at
 * most 100 characters with no control characters, shown as is and never
 * as markup. The real title from chrome() replaces it once the body lands.
 *
 * The template travels in the route list, which every device fetches, so
 * it must never hold anything private. Only route parameters are filled
 * in, on the device.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Screen
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public bool $compress = true,
        public bool $shared = false,
        public ?string $title = null,
    ) {}
}
