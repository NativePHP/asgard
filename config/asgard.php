<?php

return [

    /*
    | Path prefix for the render endpoint. Requests go to {path}/render.
    */
    'path' => env('ASGARD_PATH', '_asgard'),

    /*
    | Extra middleware for the endpoint (throttling, auth...). The route is
    | registered outside the `web` group on purpose: no session, no CSRF,
    | no cookies.
    */
    'middleware' => [],

    /*
    | Client component address => server component class.
    |
    | The address is the CLIENT component's FQCN in dot notation, each
    | segment snake-cased: App\Native\Screens\Home => app.native.screens.home
    |
    | This map, plus Asgard::component() calls at boot, is the only way an
    | address from the wire becomes a class.
    */
    'components' => [
        // 'app.native.screens.home' => App\Asgard\HomeScreen::class,
    ],

];
