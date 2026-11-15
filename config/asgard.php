<?php

return [

    /*
    | Path prefix for the endpoints: {path}/session, {path}/render,
    | {path}/call, {path}/upload, {path}/stream and {path}/routes. The app's
    | URL plus this prefix is the base URL the client is configured with.
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
    | Sessions from the handshake (Asgard protocol §6). A session expires after
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
    |
    | Auth middleware here (auth:sanctum, say) is what makes a user channel
    | from a component's channels() private. See "Targeted pushes" in the
    | README.
    */
    'middleware' => [],

    /*
    | Client component address => server component class.
    |
    | The address is the one the CLIENT component names with
    | #[EntangleWith('home')]. Without it, it is the client component's
    | FQCN in dot notation, each segment snake-cased:
    | App\Native\Screens\Home => app.native.screens.home
    |
    | This map, plus Asgard::component() calls at boot, is the only way an
    | address from the wire becomes a class.
    */
    'components' => [
        // 'home' => App\Asgard\HomeScreen::class,
    ],

    /*
    | Server screens: whole screens the server owns, each a ServerScreen
    | class with a #[Screen('/uri')] attribute. The device fetches their
    | routes from {path}/routes and opens them like its own screens. An app
    | route always wins over a server route with the same shape.
    |
    | Asgard::screen() at boot registers more.
    */
    'screens' => [
        // App\Asgard\PromoScreen::class,
    ],

    /*
    | Server-Sent Events at {path}/stream.
    |
    | Each open stream holds one PHP worker (an FPM child, say) for up to
    | `lifetime` seconds, and a device opens one stream. Size your pool for
    | the number of devices you expect to keep a server region on screen, or
    | send streams to their own pool. FPM's
    | request_terminate_timeout must be longer than `lifetime`.
    |
    | store:    cache store for push versions, channel ones included, and
    |           for shared regions' rendered answers. Must be shared by
    |           the process that calls Asgard::push() or
    |           Asgard::to() and the workers holding streams, so not `array`.
    |           null means the default cache store.
    | lifetime: seconds before the server closes a stream. The client
    |           reconnects and hears about whatever changed meanwhile.
    | poll_ms:  how often each stream reads the push store.
    */
    'stream' => [
        'store' => env('ASGARD_STREAM_STORE'),
        'lifetime' => (int) env('ASGARD_STREAM_LIFETIME', 300),
        'poll_ms' => (int) env('ASGARD_STREAM_POLL_MS', 500),
    ],

    /*
    | Base URL for relative media URLs in fragments and chrome: an image's
    | src, a list item's avatar, a nav bar logo. The device reads a relative
    | path as one of the app's own files, so the server makes each one
    | absolute before it sends it. null means asset(), which follows
    | ASSET_URL and the request's scheme and host. Behind a proxy or a
    | tunnel that needs trusted proxies, or set this.
    */
    'media_url' => env('ASGARD_MEDIA_URL'),

    /*
    | File uploads from the device to #[Upload] methods, at {path}/upload.
    |
    | max_size: the largest file any method takes, as bytes or a size such
    |           as '20M'. An #[Upload] can set a lower one, never a higher
    |           one. A bigger body is refused with 413 before it is
    |           decrypted. PHP's post_max_size and the web server's body
    |           limit (nginx client_max_body_size) must allow it too, plus
    |           about 1% and the state the device sends. upload_max_filesize
    |           does not apply. The whole file is held in memory while it is
    |           decrypted, so allow memory_limit about four times this.
    */
    'uploads' => [
        'max_size' => env('ASGARD_UPLOAD_MAX_SIZE', '20M'),
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

    /*
    | The most regions one render request may name in `regions`. A request
    | over it gets 422 too_many_regions and nothing in it is rendered. The
    | client may split the regions across smaller requests. This stops one
    | small request making the server do any amount of work.
    */
    'max_regions' => (int) env('ASGARD_MAX_REGIONS', 16),

    /*
    | Region cache. A client that keeps copies of regions sends what it holds
    | in a `cache` member, and the server skips rendering a region whose copy
    | is still fresh. See "Region cache" in the README.
    |
    | max_age: the longest, in seconds, a client may treat any region or
    |          screen as fresh, or null for no server limit. The client's
    |          own TTL still applies, and the shorter one wins. A region can
    |          set its own with #[Region('stats', maxAge: 60)], a screen
    |          with #[Screen('/x', maxAge: 60)]. 0 means never cache.
    | grace:   a fresh copy with this many seconds or less left is rendered
    |          anyway, as a normal fragment, so the client gets a new copy
    |          before the old one runs out.
    */
    'cache' => [
        'max_age' => env('ASGARD_CACHE_MAX_AGE'),
        'grace' => (int) env('ASGARD_CACHE_GRACE', 30),
    ],

    /*
    | Extra keys added to the delta of every answer that carries one: a
    | #[Server] call or an upload. They go every time, changed or not. The
    | client keeps a key only where its component has a public prop of that
    | name, and ignores the rest.
    |
    | Keys are property names: letters, digits and underscores, not starting
    | with a digit. Values are null, bools, finite numbers, UTF-8 strings or
    | arrays of those. A value may also be a callable, called with no
    | arguments each time an answer is built. Use
    | [SomeClass::class, 'staticMethod'] rather than a closure, because a
    | closure here stops `php artisan config:cache` working. A bad key or
    | value throws.
    |
    | A component's own public prop or #[Computed] of the same name wins.
    |
    | Renders carry no delta, so these keys do not reach a region render.
    */
    'state' => [
        // 'server_version' => [App\Asgard\Facts::class, 'version'],
    ],

    /*
    | Extra HTTP headers on every answer from an Asgard endpoint: the
    | handshake, renders, calls, uploads, routes, the stream and the errors
    | they give. A value may be a string, a number or a callable as for
    | `state` above. A callable that gives null leaves the header out.
    |
    | These are plain headers. The answer's encryption and signature do not
    | cover them, so TLS is all that protects them. Do not put anything in
    | them that the encryption is meant to protect.
    |
    | Names Asgard sets, signs or that a client reads are refused with an
    | exception: anything starting Content-, Asgard- or X-Asgard-, Allow,
    | Cache-Control, Connection, Keep-Alive, Set-Cookie, Signature,
    | Signature-Input, Trailer, Transfer-Encoding, Upgrade and
    | X-Accel-Buffering. So is a value with a line break.
    */
    'headers' => [
        // 'X-Server-Region' => 'eu-west',
    ],

];
