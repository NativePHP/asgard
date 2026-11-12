<?php

return [

    /*
    | Path prefix for the endpoints: {path}/session, {path}/render and
    | {path}/stream.
    */
    'path' => env('ASGARD_PATH', '_asgard'),

    /*
    | The app's Asgard key. A public identifier for one app in one
    | environment, compiled into the app. The handshake refuses any other
    | key, and every key while this is empty. `php artisan asgard:key`
    | makes one.
    */
    'key' => env('ASGARD_KEY'),

    /*
    | Sessions from the handshake (protocol §6). A session expires after
    | `lifetime` seconds without a request.
    |
    | store: cache store for sessions and action nonces. Must be shared by
    |        every worker that serves the endpoints, so not `array`. null
    |        means the default cache store.
    */
    'sessions' => [
        'store' => env('ASGARD_SESSION_STORE'),
        'lifetime' => (int) env('ASGARD_SESSION_LIFETIME', 1800),
    ],

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

    /*
    | Server-Sent Events at {path}/stream.
    |
    | Each open stream holds one PHP worker (an FPM child, say) for up to
    | `lifetime` seconds. Size your pool for the number of devices you expect
    | to keep a screen open, or send streams to their own pool. FPM's
    | request_terminate_timeout must be longer than `lifetime`.
    |
    | store:    cache store for push versions. Must be shared by the process
    |           that calls Asgard::push() and the workers holding streams, so
    |           not `array`. null means the default cache store.
    | lifetime: seconds before the server closes a stream. The client
    |           reconnects and gets fresh fragments.
    | poll_ms:  how often each stream reads the push store.
    */
    'stream' => [
        'store' => env('ASGARD_STREAM_STORE'),
        'lifetime' => (int) env('ASGARD_STREAM_LIFETIME', 300),
        'poll_ms' => (int) env('ASGARD_STREAM_POLL_MS', 500),
    ],

    /*
    | Region views: a #[Region] method may return view('...') written in the
    | app's native Blade dialect.
    |
    | compiled: where compiled region views go. null means an `asgard`
    |           folder inside view.compiled. Kept apart from web views so
    |           the two never share a compiled file.
    | elements: extra element types (snake_case) to accept as bare tags,
    |           e.g. a plugin's 'map_view' for <map-view>. The core and
    |           mobile-ui types are built in. <native:x> always works.
    */
    'blade' => [
        'compiled' => env('ASGARD_BLADE_COMPILED'),
        'elements' => [],
    ],

];
