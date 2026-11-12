# nativephp/asgard

The server half of Asgard, NativePHP's server-driven UI protocol. A NativePHP
mobile app marks regions with `@server('greeting') … @endserver`, sends its
state here, and paints the native elements this package returns.

The protocol itself is described in `docs/ASGARD-PROTOCOL.md` in mobile-air.
This package implements a v0 walking skeleton of it.

Every request and response is signed and encrypted (protocol §6 and §7), and
so is every pushed stream event. That is integrity and privacy from logging
proxies, not authentication: `ASGARD_KEY` is public, so anyone with the app can
open a session. Identify users with your own bearer auth in
`asgard.middleware`.

## Install

```sh
composer require nativephp/asgard
php artisan vendor:publish --tag=asgard-config
php artisan asgard:key
```

Put the printed `ASGARD_KEY=…` in the server's `.env` and the app's `.env`.
One key per app per environment.

## Writing a server component

```php
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class HomeScreen extends ServerComponent
{
    public string $name = 'friend';   // hydrated from the client's state

    public int $waves = 0;

    #[Locked]
    public string $role = 'guest';    // never written by the client

    #[Region('greeting')]
    public function greeting(): Node
    {
        return Node::column(
            Node::text("Hello, {$this->name}"),
            Node::text("Waves: {$this->waves}"),
            Node::button('Wave')->onPress('wave', 1),          // runs wave(1) here
            Node::button('Party')->onPressClient('celebrate'), // runs on the device
        )->class('p-4 gap-2');
    }

    #[Action]
    public function wave(int $times): void
    {
        $this->waves += $times;
    }
}
```

Register it against the address of the CLIENT component, either in
`config/asgard.php`:

```php
'components' => [
    'app.native.screens.home' => App\Asgard\HomeScreen::class,
],
```

or at boot:

```php
Asgard::component('app.native.screens.home', HomeScreen::class);
```

A region method may return a `Node`, a list of `Node`s, plain arrays in the
node shape, or a Blade view (see below). Region methods are resolved through the container, so they can
type-hint dependencies. `$this->platform()` and `$this->region()` are
available inside them.

### Blade region views

A region method can return a view written in the same Blade dialect as the
app's native screens:

```php
#[Region('stats')]
public function stats(): View
{
    return view('regions.stats', ['items' => $this->items()]);
}
```

```blade
{{-- resources/views/regions/stats.blade.php --}}
<column class="p-4 gap-2" key="stats">
    <text class="font-bold">Hello, {{ $this->name }}</text>
    @foreach ($items as $item)
        <row :key="$item->id">
            <text>{{ $item->title }}</text>
            <button @press="remove({{ $item->id }})">Remove</button>
        </row>
    @endforeach
    <button @press.client="celebrate">Party</button>
</column>
```

It becomes the same fragment as the equivalent `Node` tree, and goes through
the same checks, so chrome like `<top-bar>` is still refused.

- `<native:column>` and the bare `<column>` both work. Bare tags are accepted
  for nativephp/mobile's core elements and nativephp/mobile-ui's elements.
  Add a plugin's types (snake_case) to `asgard.blade.elements`.
  `<native:anything>` always compiles.
- `$this` is the component, and its public properties are view variables
  too. Data passed to `view()` wins over a property of the same name.
- `class` is passed through for the device to parse. `key` (or
  `native:key`) is the node key. Every other attribute is a prop, under the
  name it was written with.
- `<text>` and `<button>` take their slot as `text` and `label`, with
  whitespace collapsed as on the device. Nested `<text>` runs work.
- `@press="bump"` is a server `#[Action]`. `@press.client="celebrate"` runs a
  client action. The same goes for `@longPress`, `@doubleTap`, `@change` and
  `@submit`, and the `@tap`/`@longTap` spellings. Other events throw.
- Call args are literals (`'a'`, `2`, `-1.5`, `true`, `null`, `[...]`) or a
  whole `{{ $value }}`, which is passed as a value: `select({{ $id }}, 'x')`
  sends `$id` as an int if it is one. Nothing else is allowed, and nothing
  is evaluated from a string. `{{ }}` inside a quoted string is refused.
- `native:model`, `@model`, `native:poll` and `@navigate` are refused. A
  region has no client props to sync and re-renders by push.
- Several root tags make a list fragment. `@include`, `@if`, `@foreach` and
  the app's custom directives work.

Region views compile with their own Blade compiler into
`storage/framework/views/asgard` (`asgard.blade.compiled`), so they never
share a compiled file with web views, and the app's web views never see the
native tag rewriting. `php artisan view:clear` empties it along with the
rest. `view:cache` does not precompile region views.

### Node builder

`Node::make($type)` then `->key()`, `->props([...])` (merges), `->prop($k, $v)`,
`->class()`, `->children(...)`, and the callbacks `->onPress()`,
`->onLongPress()`, `->onDoubleTap()`, `->onChange()`, `->onSubmit()`. Shortcuts:
`Node::column(...$children)`, `Node::row(...$children)`, `Node::text('Hi')`,
`Node::button('Go')`, `Node::spacer()`, `Node::divider()`.

Each callback takes a method name and its args: `->onPress('shuffle', 3, 'x')`.
That targets an `#[Action]` method on the server. The `…Client` twin of each
(`->onPressClient('celebrate')`) targets an action the device runs itself.
Names must match `^[A-Za-z_][A-Za-z0-9_]{0,99}$`. Args must be null, bools,
numbers, strings, or lists and objects of those, at most 16 args nested at
most 4 deep. Anything else throws `InvalidFragment` when you build the node.

### Actions

A tap on a server callback sends the render request again with an `action`
key. The server hydrates the component, runs the action, then renders the
requested region and returns it. Changes the action makes to props only live
for that request. They are not sent back as state.

An action method must be public, non-static, declared on the registered class
itself (or a trait it uses), and carry `#[Action]`. It cannot also be a
`#[Region]`, and `__*` methods never count. Its params may only be typed
`string`, `int`, `float`, `bool`, `array`, `mixed`, `true`, `false`, nullable
versions of those, or left untyped. A class or interface type, `callable`,
`object`, `iterable`, `self` or a by-reference param means the method is never
callable from the wire. Args are passed by position with no container
injection and no model or enum binding.

## Pushing fresh fragments (Server-Sent Events)

A device can hold a stream open for the regions on screen. When something
changes on the server, push the component and every open stream for it
re-renders and sends the new fragments.

```php
Asgard::push('app.native.screens.home');            // every subscribed region
Asgard::push(HomeScreen::class, 'stats');           // just the stats region
```

```sh
php artisan asgard:push app.native.screens.home stats
php artisan asgard:push 'App\Asgard\HomeScreen'
```

`push()` takes a registered address or a registered class (a class registered
under several addresses is pushed to all of them). An unknown component, or a
region that is not a `#[Region]` on it, throws `InvalidArgumentException`.

A push only bumps a counter in a cache store. Each stream polls that store and
re-renders the regions whose counters moved. Every re-render is a fresh
component hydrated from the state the device sent when it connected, under the
same rules as render. Nothing is personalised beyond that state, so the same
"treat every region as public" rule applies.

**Cost:** each open stream holds one PHP worker (an FPM child, an Octane
worker…) for up to `asgard.stream.lifetime` seconds. A hundred devices on a
streamed screen means a hundred busy workers. Size the pool for it, or route
`{path}/stream` to its own pool. FPM's `request_terminate_timeout` must be
longer than the lifetime, and the push store must be shared between the
process that pushes and the workers holding streams (Redis, database, file on
one box; never `array`).
Because the endpoint is unauthenticated, anyone can open streams and tie up
workers. Put a per-IP limit on it with `asgard.middleware` (`throttle:…`) or at
the proxy.

Config, under `asgard.stream`:

| Key | Default | |
| --- | --- | --- |
| `store` | `null` (default cache store) | Cache store for push counters. `ASGARD_STREAM_STORE`. |
| `lifetime` | `300` | Seconds before the server closes a stream. The client reconnects. `ASGARD_STREAM_LIFETIME`. |
| `poll_ms` | `500` | How often each stream reads the push store. `ASGARD_STREAM_POLL_MS`. |

## Sessions and security

The app opens a session at `POST {base}/_asgard/session` with its key and an
ephemeral P-256 public key. The server answers with its own public key and a
session id, and both sides derive the session secret with ECDH and HKDF. The
secret is never sent. Sessions live in a cache store and expire after
`lifetime` seconds without a request.

```json
→ {"key":"<ASGARD_KEY>","protocol":1,"accepts":["tree/json"],"client_public":"<base64url point>"}
← {"session":"<id>","server_public":"<base64url point>","expires_in":1800,"encoding":"tree/json"}
```

Render and stream requests then carry `Asgard-Session-Id`, an `aes128gcm` body
(RFC 8188), `Content-Digest` (RFC 9530) and an `hmac-sha256` signature under
the label `asgard` (RFC 9421). Server actions also sign a nonce, and a nonce
is refused the second time. The `SealAsgardMessages` middleware checks all of
it before your middleware or any controller sees the request, and seals
whatever comes back. Stream events are sealed one by one and bound to the
request that opened the stream.

Config, under `asgard.sessions`:

| Key | Default | |
| --- | --- | --- |
| `store` | `null` (default cache store) | Cache store for sessions and nonces. Shared by every worker, so never `array`. `ASGARD_SESSION_STORE`. |
| `lifetime` | `1800` | Seconds a session lives without a request. `ASGARD_SESSION_LIFETIME`. |

Fragments of 1 KiB or more are gzipped before they are encrypted. A response
says which with the signed `Asgard-Content-Coding: gzip` (or `identity`)
header, and `Content-Encoding` stays `aes128gcm`, because Cloudflare rewrites
a stacked `gzip, aes128gcm` and that breaks the signature. Errors are never
compressed.
Turn it off for a region whose fragment mixes a secret with text someone else
can choose, because the compressed length can leak the secret (the BREACH
attack):

```php
#[Region('account', compress: false)]
```

The crypto lives in `src/Security`, in small classes with no Laravel in them.
`nativephp/midgard` carries an identical copy, and both test suites check
`tests/Fixtures/asgard-interop.json` so the two cannot drift apart.

## Wire contract (v0)

The bodies below are the plaintext. On the wire each one is inside
`aes128gcm`, as described above.

**Endpoint:** `{base}/_asgard/render`. The prefix is `asgard.path`. Two forms:

- `QUERY` with a JSON body.
- `POST` with header `X-Asgard-Method: QUERY`. A POST without it gets 405.

The route sits outside the `web` group: no session, no CSRF, no cookies. Add
throttling or auth with `asgard.middleware`. `php artisan serve` cannot do
QUERY, so use the POST form there.

**Request headers:** `Content-Type: application/json`,
`Accept: application/json`, `Asgard-Protocol: 1`.

**Request body:**

```json
{"protocol":1,"component":"app.native.screens.home","region":"greeting","state":{"name":"Simon"},"platform":"ios"}
```

An action request adds `action`. `call` and `args` are both required.
`target` may be left out or set to `"server"`. Any other value, including
`"client"`, is a 422.

```json
{"protocol":1,"component":"app.native.screens.home","region":"greeting","state":{"name":"Simon"},"platform":"ios",
 "action":{"call":"wave","args":[1]}}
```

`component` is the client component's FQCN in dot notation with each segment
snake-cased. `platform` is `ios` or `android`. A malformed request gets 422
`{"error":"invalid_request","message":"…"}`.

**Response 200:**

```json
{"protocol":1,"region":"greeting","fragment":{"type":"column","class":"p-4 gap-2","children":[{"type":"text","props":{"text":"Hello"}}]}}
```

`fragment` is one node or a list of nodes:
`{type, key?, props?, class?, on_press?, on_long_press?, on_double_tap?, on_change?, on_submit?, children?}`.

Each callback slot is an object, never a string:

```json
"on_press": {"call": "shuffle", "args": [3, "x"]}
"on_press": {"call": "celebrate", "args": [], "target": "client"}
```

No `target` means the server. An explicit `"server"` is dropped on the way out.

**Errors:**

| Status | Body | When |
| --- | --- | --- |
| 403 | `{"error":"forbidden",…}` | Digest, signature or decryption failed, the signature expired, or a nonce was replayed. Plain, unsigned. Do not retry. |
| 403 | `{"error":"nonce_required"}` | An action whose signature has no nonce. |
| 404 | `{"error":"unknown_component"}` | Unknown component, or a region it doesn't declare. Same body for both. |
| 404 | `{"error":"unknown_action"}` | `action.call` is not a callable `#[Action]` on the component, or the arg count doesn't fit it. |
| 405 | `{"error":"method_not_allowed",…}` | POST without `X-Asgard-Method: QUERY`. |
| 419 | `{"error":"session_expired",…}` | No session, or the server no longer knows it. Plain, unsigned. Handshake again and retry once. |
| 422 | `{"error":"invalid_request","message":…}` | Bad body, bad `action` shape, state that doesn't fit a prop's type, or an arg that doesn't fit its param's type. |
| 500 | `{"error":"invalid_fragment"}` | The region returned a bad fragment. The message is included in debug. |

### Stream

**Endpoint:** `{base}/_asgard/stream`, in the same two forms as render
(`QUERY`, or `POST` with `X-Asgard-Method: QUERY`).

**Request body:** like render, but `regions` (1 to 16 distinct region names)
instead of `region`, and no `action`:

```json
{"protocol":1,"component":"app.native.screens.home","regions":["greeting","stats"],"state":{"name":"Simon"},"platform":"ios"}
```

Everything is checked before the stream starts, and failures are the same JSON
errors as render: 405, 422 (bad body, bad `regions`, an `action` key, state
that doesn't fit), 404 `unknown_component` (unknown component, or any region
it doesn't declare), 500 `invalid_fragment`.

**Response 200:** `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
`X-Accel-Buffering: no`. The body:

```
retry: 3000

id: 0
event: fragment
data: {"body":"<base64url aes128gcm>","enc":"gzip","sig":"<base64url hmac>"}

id: 0
event: fragment
data: {"body":"…","enc":"identity","sig":"…"}

: ping

id: 1
event: fragment
data: {"body":"…","enc":"identity","sig":"…"}

```

Each `body` decrypts with `enc_s`, then gunzips when `enc` is `gzip`, to
`{"protocol":1,"region":"stats","fragment":{...}}`. `sig` is HMAC-SHA256 with
`sig_s` over `asgard/1 event`, the stream request's signature in base64, the
event id, the event name, `enc` and `body`, one per line. An event only
verifies on the stream it was sent on.

- One `fragment` event per region on connect, in the order asked for.
- After that, a `fragment` event for each region a push touched.
- `: ping` every 15 seconds.
- `id` is the sum of the push counters for this component and its subscribed
  regions, so it only goes up while the store keeps its keys. It is
  informational. A reconnect with `Last-Event-ID` still gets every region's
  current fragment first.
- The server closes the stream after `asgard.stream.lifetime` seconds or
  when the client goes away. Reconnect after `retry`.
- If a re-render throws or returns a bad fragment, it is reported and that
  region is skipped. The device keeps its last fragment.

## Security rules

- The wire `component` is only ever a key into the registry. It is never
  turned into a class name.
- A region is only reachable if a public, non-static method declared on the
  component class carries `#[Region('name')]`. The map is built by
  reflection and cached per class. The wire `region` is only ever a key into
  that map.
- State hydrates only into public, non-static, non-readonly, typed props
  declared on the component class itself, and not `#[Locked]`. Allowed types
  are `string`, `int`, `float`, `bool`, `array` and nullable versions. Values
  must be scalars, null or arrays of those; no coercion and no objects. A
  mismatch is 422. Unknown keys are ignored, and logged when `app.debug` is on.
- An action is only reachable if a public, non-static, non-magic method
  declared on the component class carries `#[Action]` and not `#[Region]`.
  Methods on `ServerComponent` and on parent classes never count. Params are
  limited to plain JSON types, and each arg is type checked strictly before
  the call (no `"5"` into `int`). The wire `call` is only ever a key into that
  map, and region resolution happens first, so an action never runs for a
  region that doesn't exist.
- Fragments are validated before they leave. Callback slots must be
  `{call, args, target?}` objects. Screen chrome (`top_bar*`,
  `bottom_nav*`, `bottom_bar`, `side_nav*`, `fab`) and `server_region` are
  rejected. Props keys starting `_`, `native-` or `native:` are stripped.
  Unknown node fields are rejected.
- The stream endpoint applies the same checks as render to every region it
  is asked for, before any output. Re-renders use a fresh component hydrated
  from the original state. Pushes come only from server code.

## Tests

```sh
composer test
```
