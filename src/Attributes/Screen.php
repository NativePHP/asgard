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
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Screen
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public bool $compress = true,
        public bool $shared = false,
    ) {}
}
