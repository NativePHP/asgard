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
- `@navigate="/promos/{{ $id }}"` moves the app to a route the device
  knows. See [Navigation](#navigation).
- `native:model`, `@model` and `native:poll` are refused. A region has no
  client props to sync and re-renders by push.
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

### Server methods

A `#[Server]` method is a method call from the device that runs here. The
device's component and the server component each have a method of the same
name, both marked `#[Server]`. The device body runs first (a loading flag, an
optimistic update), then the call comes here with the device's state:

```php
use Native\Asgard\Attributes\Server;

#[Server]
public function roll(): void
{
    $this->result = random_int(1, 6);
}
```

The server hydrates the component from that state, runs the method and sends
back only the public props whose value changed, compared by their
`serialize()` form. The device applies each one only if its own value has not
moved since it sent the call. A `#[Locked]` prop the method changes is sent
back too. A changed prop must be null, a bool, a finite number, a UTF-8
string or an array of those, or the call is a 500.

The method follows the same rules as an action: public, non-static, declared
on the registered class itself (or a trait it uses), not a `#[Region]`, and
params limited to plain JSON types. `#[Action]` and `#[Server]` are separate
allowlists, so an action cannot be called as a server method or the other
way round. Nothing is rendered, so the answer carries no fragment. A device
that wants a region redrawn after a call asks for it again.

## Server screens

A region lives inside a screen the app wrote. A server screen is a whole
screen the server owns, at a route of its own:

```php
use Native\Asgard\Attributes\Action;
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Chrome\Chrome;
use Native\Asgard\Chrome\NavAction;
use Native\Asgard\Chrome\NavBar;
use Native\Asgard\Node;
use Native\Asgard\ServerScreen;

#[Screen('/promos/{id}')]
class PromoScreen extends ServerScreen
{
    public int $id;           // filled from the route parameter

    public int $likes = 0;

    public function render(): Node
    {
        return Node::column(
            Node::text("Promo {$this->id}"),
            Node::button('Next')->onPressNavigate('/promos/'.($this->id + 1), transition: 'fade'),
        );
    }

    public function chrome(): Chrome
    {
        return Chrome::make()
            ->native()
            ->navBar(NavBar::make()->title("Promo #{$this->id}")->back()
                ->action(NavAction::make('like')->icon('heart')->onPress('like')));
    }

    #[Action]
    public function like(): void
    {
        $this->likes++;
    }
}
```

Register it in `config/asgard.php` under `screens`, or with
`Asgard::screen(PromoScreen::class)` at boot. The device fetches the route
list from `{path}/routes` (sealed like render) and opens a matching URI in a
generic screen. An app route always wins over a server route with the same
shape: a server screen can add a route, never replace one.

- The URI uses Laravel's spelling: literal segments and `{name}`
  parameters, with an optional `{name?}` last.
- `#[Screen('/uri', name: 'promos.show')]` sets the wire address. It
  defaults to the class name in dot notation.
- Route parameters and the query string arrive as state. Public typed
  properties named after them are filled in, and numeric and boolean
  strings are converted to the property's type.
- The body travels as region `screen`. `render()` returns what a region
  method can: a `Node`, a list, node arrays, or a Blade view.
- `#[Action]` methods and `Asgard::push()` work as on any component.
  `#[Region]` methods do not: a screen has one body.

### Chrome

`chrome()` returns a `Chrome`, the array form of it, or null for none. It
travels as its own part of the answer and is checked on its own. Chrome is
never accepted inside the body, so `Node::make('top_bar')` or a `<top-bar>`
tag in a screen view is refused.

- `->native()` draws the bars with the platform's own navigation.
  `->font('Inter')` sets a font for both bars.
- `->navBar(NavBar::make()...)`: `title`, `subtitle`, `back`, `logo`,
  `titleView`, colours, `displayMode`, `scrollBehavior`, `searchBar`, and
  `action(NavAction::make('id')...)` buttons with an icon or label, and
  `items([...])` for a menu.
- `->tabBar(TabBar::make()->add(Tab::link('Home', '/', icon: 'home')))`, or
  `->hidden()` to hide the app's tab bar on this screen.
- `->bottomBar($content)` and `->tabAccessory($content)` take a fragment:
  a `Node`, a list, or a Blade view, rendered like the body.

Chrome buttons take the same callbacks as nodes: `onPress`,
`onPressClient`, `onPressNavigate` and `onPressBack`.

### Navigation

A press can move the app instead of calling a method:

```php
Node::button('Next')->onPressNavigate('/promos/43');
Node::button('Home')->onPressNavigate('/', replace: true, transition: 'fade');
Node::button('Close')->onPressBack();
```

`onLongPressNavigate` and `onDoubleTapNavigate` exist too. A change or a
submit never navigates. The path must be an app path: one leading `/`, no
scheme, no host, no whitespace. Web URLs throw `InvalidFragment`. Whether a
route matches is up to the device, which checks its own routes and then
the server's before it goes. Transitions are `slide_from_right`,
`slide_from_left`, `slide_from_bottom`, `fade`, `fade_from_bottom`,
`scale_from_center`, `parallax_push`, `morph` and `none`.

In Blade, `@navigate` does the same on `on_press`:

```blade
<button @navigate="/promos/{{ $next }}">Next</button>
<button @navigate.replace.fade="/">Home</button>
<button @navigate.back>Close</button>
```

- The value is a path with `{{ }}` interpolated into it, so it is always a
  string. It is not a PHP expression, and the device's
  `@navigate('/x', [...])` form, with data, is refused.
- Modifiers: `replace`, `back`, and one transition in the device's
  camelCase spelling (`fade`, `slideFromRight`, `slideFromLeft`,
  `slideFromBottom`, `fadeFromBottom`, `scaleFromCenter`, `parallaxPush`,
  `morph`, `none`). `exitToWeb` and any other modifier fail the compile.
- `@navigate` and `@press` (or `@tap`) on one element fail the compile. On
  the device `@navigate` silently wins; here a press does one thing. Call
  an action that navigates if you need both.

### Blade screens

`render()` can return a view, and so can the chrome's content slots:

```php
public function render(): View
{
    return view('screens.promo');   // $this and public properties are available
}

public function chrome(): Chrome
{
    return Chrome::make()->native()
        ->navBar(NavBar::make()->title("Promo #{$this->id}")->back())
        ->bottomBar(view('screens.promo-bar'));
}
```

Both compile like region views. The server never sends Blade to the
device. It compiles the view into the same fragment JSON a `Node` tree
would make.

## Pushing fresh fragments (Server-Sent Events)

A device holds one stream open for every server region it has on screen.
When something changes on the server, push the component and every stream
watching it tells its device. By default that is a signal, and the device
fetches the region through render with its own user. A region marked
`shared: true` is sent inline instead.

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

The stream carries a subscription per component instance on screen: its
address, its regions and the state they were rendered for. Two screens of one
route with different parameters (`/promos/1` under `/promos/2`) are two
subscriptions, and each gets its own pushes. The device sends the whole set
each time it changes, and drops instances that are gone, so the stream reopens
on navigation.

A push only bumps a counter in a cache store. Each stream polls that store and
sends a `signal` for each region whose counters moved: the region and its new
version, nothing rendered. The device fetches it with a normal render request,
so it gets the region exactly as render would give it, user and all.

### Shared regions

```php
#[Region('likes', shared: true)]
public function likes(): Node { ... }

#[Screen('/promos/{id}', shared: true)]
class PromoScreen extends ServerScreen { ... }
```

A shared region renders the same for everyone with the same state. For those
the stream sends a `fragment` event with the new fragment in it, so devices
skip the round trip. It is rendered once per address, region, platform, state
and version, by the first stream that needs it, and kept in the push store for
60 seconds for every other stream to reuse. Each stream still seals it for its
own session.

The cache key has no user in it, so a shared region renders on the stream with
nobody signed in: `auth()`, the default guard and `$request->user()` all see a
guest. A shared region must not depend on the user or on secrets (and must not
name a guard of its own to get round that). Because it holds nothing private,
compressing it is safe. On the render endpoint a shared region renders as
usual, with the user.

The default is a signal, so a region you forgot to mark costs a round trip,
never a leak.

Render answers carry a `version` token per region, worked out from the
counters that region watches. The device sends it back when it subscribes,
and the stream only signals (or sends) regions whose token has moved since. So reopening the stream on every navigation does not re-render every
region on screen.

**Cost:** each open stream holds one PHP worker (an FPM child, an Octane
worker…) for up to `asgard.stream.lifetime` seconds. That is one worker per
device with server regions on screen, so a hundred such devices means a
hundred busy workers. Size the pool for it, or route
`{path}/stream` to its own pool. FPM's `request_terminate_timeout` must be
longer than the lifetime, and the push store must be shared between the
process that pushes and the workers holding streams (Redis, database, file on
one box; never `array`).
Because the endpoint is unauthenticated, anyone can open streams and tie up
workers. Put a per-IP limit on it with `asgard.middleware` (`throttle:…`) or at
the proxy.

### Targeted pushes (channels)

`Asgard::push()` reaches every open stream for a component. To reach only
some, a component names the channels its streams join, and you push to those
channels with `Asgard::to()`.

```php
class PromoScreen extends ServerScreen
{
    public int $id;

    public function channels(): array
    {
        $channels = ["promo.{$this->id}"];

        if (auth()->check()) {
            $channels[] = 'user.'.auth()->id();   // or auth()->user()
        }

        return $channels;
    }
}
```

```php
Asgard::to('promo.42')->push(PromoScreen::class, 'screen'); // one region, in promo.42
Asgard::to('promo.42')->push(PromoScreen::class);           // the whole component, in promo.42
Asgard::to(['user.5', 'user.9'])->push();                   // every stream in those channels
Asgard::to($user)->push();                                  // App.Models.User.5
```

```sh
php artisan asgard:push --to=user.5 --to=user.9
php artisan asgard:push 'App\Asgard\PromoScreen' screen --to=promo.42
```

- `channels()` runs once per subscription when a stream opens, on a
  component hydrated from that subscription's state, after the app's
  middleware. Render calls it too, to work out the region's version token. So `auth()` sees the
  request's user. The default is no channels.
- A channel is a string of 1 to 200 bytes with no control characters, or an
  Eloquent model (any `HasBroadcastChannel`), named the way Laravel
  broadcasting names it: `App.Models.User.5`. Repeats are dropped, and a
  subscription joins at most 16.
- A bad `channels()` answer is a bug in the app. With `app.debug` on, the
  stream request fails with the exception. In production the problem is
  reported, the bad entries are dropped and the subscription keeps the first
  16 good channels. It still opens and still gets pushes to everyone.
- `to()` takes names, models or lists of them. The component and region are
  checked as `push()` checks them, and a region needs a component.
- A subscription for a component in a joined channel re-renders a region when the
  channel, the channel and component, or the channel, component and region is
  pushed. Pushes to everyone still arrive as before.

**`channels()` is the authorization step.** The device never sends channels
and there is no separate check. But anyone holding the app's `ASGARD_KEY`
(it ships inside the app) can open a stream with any state they like. So a
channel built from client state, like `"promo.{$this->id}"`, is only as
private as that state: anyone can join `promo.42` by sending `id` 42. A
private channel must come from the authenticated user (`'user.'.auth()->id()`),
with auth middleware such as `auth:sanctum` on the Asgard routes through
`asgard.middleware`. Never build a user channel from a public prop, and check
`auth()->check()` first so a guest does not join `user.`.

A push carries no data. Each subscription re-renders with its own state and
the stream's user, so a channel push cannot leak one user's fragment to another. Your
render code still has to authorize what it shows, exactly as it does for a
normal render.

Limits:

- A stream carries at most 16 subscriptions, each of 1 to 16 regions.
- A stream works out its channels once. It does not notice a changed bearer
  token (a sign-in, a sign-out, another user) until it reopens, at the latest
  after `asgard.stream.lifetime` seconds.

Config, under `asgard.stream`:

| Key | Default | |
| --- | --- | --- |
| `store` | `null` (default cache store) | Cache store for push counters, channel counters included. `ASGARD_STREAM_STORE`. |
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

Render, call and stream requests then carry `Asgard-Session-Id`, an `aes128gcm` body
(RFC 8188), `Content-Digest` (RFC 9530) and an `hmac-sha256` signature under
the label `asgard` (RFC 9421). Server actions and calls also sign a nonce, and a nonce
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

### Call

**Endpoint:** `{base}/_asgard/call`, in the same two forms as render. Like an
action, its signature must carry a nonce.

**Request body:** the component, the `#[Server]` method, its args by position,
the device's state after its own body ran, and the platform. Nothing else is
allowed.

```json
{"protocol":1,"component":"app.native.screens.home","method":"roll","args":[],"state":{"rolling":true},"platform":"ios"}
```

**Response 200:** the props the method changed. `delta` is always an object,
`{}` when nothing changed. It is never gzipped, since it can mix the server's
values with text the device chose.

```json
{"protocol":1,"delta":{"result":4}}
```

**Errors:** as render, except:

| Status | Body | When |
| --- | --- | --- |
| 403 | `{"error":"nonce_required"}` | The signature has no nonce. |
| 404 | `{"error":"unknown_component"}` | The address is not registered. |
| 404 | `{"error":"unknown_method"}` | `method` is not a callable `#[Server]` method on the component, or the arg count doesn't fit it. |
| 422 | `{"error":"invalid_request","message":…}` | Bad body, an unknown field, state that doesn't fit a prop's type, or an arg that doesn't fit its param's type. |
| 500 | `{"error":"invalid_delta"}` | The method left a changed prop that is not a plain JSON value. The message is included in debug. |

### Stream

**Endpoint:** `{base}/_asgard/stream`, in the same two forms as render
(`QUERY`, or `POST` with `X-Asgard-Method: QUERY`).

**Request body:** the device's platform and 1 to 16 subscriptions, one per
component instance on screen. No `action`.

```json
{"protocol":1,"platform":"ios","subscriptions":[
 {"id":"3f9a0c1d2e4b5a6c","component":"app.native.screens.home","regions":["greeting","stats"],
  "state":{"name":"Simon"},"versions":{"greeting":"q3V0bG9yZW1pcHN1"}}]}
```

- `id` is the device's own name for the subscription: 1 to 32 characters of
  `A-Z a-z 0-9 _ -`, unique within the request. Events name it.
- `regions` is 1 to 16 distinct region names, checked as render checks one.
- `state` is checked and hydrated as render's is, separately per subscription.
- `versions` is optional. It maps a region to the `version` token the device
  holds for it, from a render answer or an earlier event.

Everything is checked before the stream starts, and failures are the same JSON
errors as render: 405, 422 (bad body, bad subscriptions, an `action` key, state
that doesn't fit), 404 `unknown_component` (unknown component, or any region
it doesn't declare, in any subscription), 500 `invalid_fragment`.

Render answers carry the token too, next to `region`:
`{"protocol":1,"region":"greeting","version":"q3V0bG9yZW1pcHN1","fragment":{…}}`.
It is opaque, up to 64 characters of `A-Z a-z 0-9 _ -`.

**Response 200:** `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
`X-Accel-Buffering: no`. The body:

```
retry: 3000

id: 0
event: signal
data: {"body":"<base64url aes128gcm>","enc":"identity","sig":"<base64url hmac>"}

id: 0
event: fragment
data: {"body":"…","enc":"gzip","sig":"…"}

: ping

id: 1
event: fragment
data: {"body":"…","enc":"identity","sig":"…"}

```

Each `body` decrypts with `enc_s`, then gunzips when `enc` is `gzip`, to
`{"protocol":1,"subscription":"3f9a0c1d2e4b5a6c","region":"stats","version":"…"}`
for a signal, plus `fragment` (and `chrome` for a server screen) for a shared
region's fragment event. `sig` is HMAC-SHA256 with
`sig_s` over `asgard/1 event`, the stream request's signature in base64, the
event id, the event name, `enc` and `body`, one per line. An event only
verifies on the stream it was sent on.

- On connect, one event per region whose current token differs from the one
  the device sent (or that it sent none for), in the order asked for.
- After that, one event for each region a push touched, in every
  subscription it touched.
- The event is `signal` for a region that is not shared. Its body is
  `{"protocol":1,"subscription":…,"region":…,"version":…}`, never compressed,
  and it is signed with `signal` as the event name. The device fetches the
  region through render unless it already holds that version.
- The event is `fragment` for a shared region, with the fragment (and chrome)
  in the body.
- `: ping` every 5 seconds. PHP only notices a gone client when it writes, so
  this is also how soon a dead connection gives its worker back.
- `id` is the sum of the push counters the stream watches, so it only goes
  up while the store keeps its keys. It is informational. `Last-Event-ID` is
  ignored; tokens decide what is sent on connect.
- The server closes the stream after `asgard.stream.lifetime` seconds or
  when the client goes away. Reconnect after `retry`.
- If a shared region's render throws or returns a bad fragment, it is
  reported and that region is skipped. The device keeps its last fragment.

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
- A call is only reachable for a method that passes the same checks as an
  action but carries `#[Server]`. The wire `method` is only ever a key into
  that map. A call needs a nonce, and a nonce is refused the second time.
- Fragments are validated before they leave. Callback slots must be
  `{call, args, target?}` objects. Screen chrome (`top_bar*`,
  `bottom_nav*`, `bottom_bar`, `side_nav*`, `fab`) and `server_region` are
  rejected. Props keys starting `_`, `native-` or `native:` are stripped.
  Unknown node fields are rejected.
- The stream endpoint applies the same checks as render to every region of
  every subscription, before any output. It renders only shared regions, on
  a fresh component hydrated from the subscription's state, with nobody
  signed in. Everything else is a signal and is rendered by render. Pushes come only from server code.
- Channels come only from the component's `channels()` on the server. They
  are as private as what they are built from, so private channels must come
  from the authenticated user, never from state.

## Tests

```sh
composer test
```
