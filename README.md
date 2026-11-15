# nativephp/asgard

The server half of Asgard, NativePHP's server-driven UI protocol. A NativePHP
mobile app marks regions with `@server('greeting') … @endserver`, sends its
state here, and paints the native elements this package returns.

The protocol itself is at [NativePHP/asgard-protocol](https://github.com/NativePHP/asgard-protocol).
This package implements a v0 walking skeleton of it, following Draft 2.

Every request and response is signed and encrypted (Asgard protocol §6 and §7), and
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
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Attributes\ServerAction;
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

    #[ServerAction]
    public function wave(int $times): void
    {
        $this->waves += $times;
    }
}
```

Register it against the address of the CLIENT component. The client
component names its address with an attribute:

```php
#[EntangleWith('home')]
class Home extends NativeComponent
```

and the server maps that address to its component, either in
`config/asgard.php`:

```php
'components' => [
    'home' => App\Asgard\HomeScreen::class,
],
```

or at boot:

```php
Asgard::component('home', HomeScreen::class);
```

Without the attribute, the address is the client component's class name in
dot notation, each segment snake-cased: `App\Native\Screens\Home` becomes
`app.native.screens.home`. Naming it keeps the two sides from depending on
the client's class name, so you can move or rename the class in the app
without touching the server.

A region method may return a `Node`, a list of `Node`s, plain arrays in the
node shape, or a Blade view (see below). Region methods are resolved through the container, so they can
type-hint dependencies. `$this->region()` is available inside them.

### What the client tells you about itself

The protocol has no field for the client's platform, app version or anything
else about it. A client that wants the server to know such a thing sends it
in the state, and you read it like any other state, with a public property:

```php
public string $platform = '';   // midgard sends this by default
```

A state key with no matching property is ignored (and logged under
`app.debug`), so a client can send keys only some components care about. The
key still counts as state: a [shared region](#shared-regions) is cached per
whole state, so two clients that differ only in a key you never read still get
their own renders.

Plain HTTP headers the app adds work too, through Laravel as usual:

```php
request()->header('X-App-Version');
```

Apart from `Authorization`, which the signature covers when it is there, they
are not part of the signature, so the signature still verifies whatever the app
adds. They are covered by TLS but not by
Asgard's body encryption, so do not put anything in them that the encryption is
meant to protect. And a shared region's cache key does not include them, so a
shared region must not depend on a header. Send it in the state instead when it
should change what renders.

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
  `<native:anything>` always compiles. Compiling a tag does not mean the
  device can draw it: see "Element types" below.
- `$this` is the component, and its public properties are view variables
  too. Data passed to `view()` wins over a property of the same name.
- `class` is passed through for the device to parse. `key` (or
  `native:key`) is the node key. Every other attribute is a prop, under the
  name it was written with.
- `<text>` and `<button>` take their slot as `text` and `label`, with
  whitespace collapsed as on the device. Nested `<text>` runs work.
- `@press="bump"` is a server `#[ServerAction]`. `@press.client="celebrate"` runs a
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

### Images and other media

Write media URLs the way you would on the web:

```blade
<image src="/storage/promo.jpg" />
<image src="{{ asset('img/logo.png') }}" />
<image src="img/heimdall.png" />
```

On the device a relative `src` means one of the app's own files, so Asgard
makes every media URL absolute before a fragment or chrome leaves the
server. That covers `src` on any element, `leadingAvatar` and
`leadingImage` on a list item, a nav bar logo and a nav action's image.

- `img/x.png`, `./img/x.png` and `/img/x.png` become `{base}/img/x.png`.
- `//cdn.test/x.png` gets the base's scheme.
- `http:` and `https:` URLs pass through.
- Any other scheme (`javascript:`, `file:`, `content:` and so on) is an
  invalid fragment. So is `data:`: platforms differ on it, and a fragment is
  re-sent on every push, so serve the image from a URL instead.

The base is `asset()`, which follows `ASSET_URL` and the request's scheme
and host. Behind a proxy, a load balancer or a tunnel, trust the proxy
(Laravel's `trustProxies` middleware) so the request says `https` and the
public host, or set `asgard.media_url` (`ASGARD_MEDIA_URL`) to the base you
want. The device only loads `https:` media, plus `http:` when the app is
built with `app.debug` on.

The device loads media itself, with no Asgard session and none of the
user's auth. Private media needs a URL that carries its own permission:
`URL::temporarySignedRoute()` or `Storage::temporaryUrl()`.

### Element types

A fragment may use the core types every client draws: `column`, `row`,
`stack`, `scroll_view`, `text`, `button`, `image`, `spacer` and `divider`.
Anything else (`list_item`, `toggle`, a plugin's `map_view`) needs the
client to list it in `elements` when it opens its session. A region that
returns a type the client did not list is an invalid fragment, a 500, and
the device keeps its placeholder. So does chrome whose `titleView`,
`bottomBar` or `tabAccessory` uses one.

Screen chrome and `server_region` are never allowed in a fragment, whatever
the client lists.

### Node builder

`Node::make($type)` then `->key()`, `->props([...])` (merges), `->prop($k, $v)`,
`->class()`, `->children(...)`, and the callbacks `->onPress()`,
`->onLongPress()`, `->onDoubleTap()`, `->onChange()`, `->onSubmit()`. Shortcuts:
`Node::column(...$children)`, `Node::row(...$children)`, `Node::text('Hi')`,
`Node::button('Go')`, `Node::spacer()`, `Node::divider()`.

Each callback takes a method name and its args: `->onPress('shuffle', 3, 'x')`.
That targets a `#[ServerAction]` method on the server. The `…Client` twin of each
(`->onPressClient('celebrate')`) targets an action the device runs itself.
Names must match `^[A-Za-z_][A-Za-z0-9_]{0,99}$`. Args must be null, bools,
numbers, strings, or lists and objects of those, at most 16 args nested at
most 4 deep. Anything else throws `InvalidFragment` when you build the node.

### Actions

A tap on a server callback sends the render request again with an `action`
key. The server hydrates the component, runs the action, then renders the
requested region and returns it. The props the action changed come back in
the answer's `delta`, as a call's do, and the device applies them before it
paints the region.

An action method must be public, non-static, declared on the registered class
itself (or a trait it uses), and carry `#[ServerAction]`. It cannot also be a
`#[Region]`, and `__*` methods never count. Its params may only be typed
`string`, `int`, `float`, `bool`, `array`, `mixed`, `true`, `false`, nullable
versions of those, or left untyped. A class or interface type, `callable`,
`object`, `iterable`, `self` or a by-reference param means the method is never
callable from the wire. Args are passed by position with no container
injection and no model or enum binding.

### Server methods

A `#[Server]` method is a method call from the device that runs here. The
device's component has a method marked `#[Server]`, and the server component
has a method of the same name marked `#[ServerAction]`. The device body runs
first (a loading flag, an optimistic update), then the call comes here with
the device's state:

```php
use Native\Asgard\Attributes\ServerAction;

#[ServerAction]
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

`#[ServerAction]` is the one allowlist for both taps and device calls. Any
method marked with it can be reached from a fragment's callback and from a
device's `#[Server]` method, and a public method without it can be reached
from neither. The rules are the ones above: public, non-static, declared on
the registered class itself (or a trait it uses), not a `#[Region]`, and
params limited to plain JSON types. Nothing is rendered unless the device
names regions in the call, with `#[Server(render: ...)]` on its side, or the
method asks for them with `refresh()`, below. Both lists are rendered
together once the method has run.

Every other answer sends state back too. An action, a tap inside a region,
sends back what it changed exactly as a call does. A plain render sends back
any prop that changed while it rendered. See "State in every answer" below.

### Computed values

A public method marked `#[Computed]` is a value the server works out. It is
called each time an answer is built, after the method or the render ran. A
call, an upload or an action puts it in the delta under the method's name
every time, whether it changed or not. A plain render puts it there only
when the device sent a different value under that name:

```php
use Native\Asgard\Attributes\Computed;

public array $items = [];

#[Computed]
public function total(): int
{
    return array_sum($this->items);
}
```

The device's component keeps it only if it has a public prop called `total`.
A computed value is read-only here. State the device sends under its name is
ignored, like any key without a prop, and it can never be run as a server
action, an upload or a region. It must be public and non-static with no
required parameters, it may not carry `#[ServerAction]`, `#[Upload]` or
`#[Region]`, and it may not share its name with a public prop.
Breaking any of those throws when the component is first used. A parent
class's `#[Computed]` methods count too.

Its value must be plain JSON, as a changed prop's must, or the answer is a
500 `invalid_delta`. If it throws, the answer is what it would be had the
method thrown. A region can call it like any other method, as
`$this->total()`.

### Uploads

An `#[Upload]` method takes a file from the device. The app sends it with
`$this->uploadToServer('savePhoto', $path)` from a component that uses
`UsesServer`. There is no method of that name on the device.

```php
use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Upload;

#[Upload(maxSize: '10M', mimes: ['image/jpeg', 'image/png'])]
public function savePhoto(UploadedFile $file, string $caption = ''): void
{
    $this->photo = $file->store('photos', 'public');
}
```

The first parameter must take an `UploadedFile`. Any others are args from the
device, checked as an action's are. Everything else works as a device call
does: the component is hydrated from the device's state, the answer is
the props the method changed, and `refresh()` works the same way.
`#[Upload]` is its own allowlist, apart from `#[ServerAction]`.

- `maxSize` is bytes, or a size like `'512K'`, `'10M'` or `'1G'`. It can lower
  `asgard.uploads.max_size` (20M by default) but never raise it. Too big is a
  413.
- `mimes` lists the types the file may have. The server sniffs the type from
  the bytes with `finfo`, and ignores what the device said. `'image/*'`
  matches any image. Leave it out to take any type. A type not on the list is
  a 415.
- The file is in a temp file the server named, and it is deleted when the
  method returns. Store it to keep it. `getClientOriginalName()` is the
  device's filename, cleaned up, for display only. Never build a path from it.
  `getClientMimeType()` is the sniffed type.

The whole file goes in one sealed request body, so the server's limits must
let it through:

- PHP's `post_max_size` must be above `max_size` plus a little (the frame
  header holds the state, and encryption adds about 0.4%). `upload_max_filesize`
  does not apply, since this is not a multipart form.
- nginx's `client_max_body_size` (1M by default) must allow the same. Other
  proxies and WAFs have their own body limits.
- The body is held in memory while it is checked and decrypted. Allow
  `memory_limit` about four times `max_size`.
- A body bigger than `max_size` allows is refused with a plain 413 before it is
  hashed or decrypted.

### App-wide state and headers

Two options in `config/asgard.php` add the same facts to every answer.

`state` adds keys to the delta of every answer, after the changed props and
computed values. A call, an upload or an action sends them every time. A
plain render sends one only when the device sent a different value under
that name:

```php
'state' => [
    'server_version' => [App\Asgard\Facts::class, 'version'],
],
```

Keys are property names: letters, digits and underscores, not starting with a
digit, at most 100 characters. A value is null, a bool, a finite number, a
UTF-8 string or an array of those. It may also be a callable, called with no
arguments each time an answer is built. Use a `[Class::class, 'method']`
callable rather than a closure, because a closure in config stops
`php artisan config:cache` working. A bad key or value throws. A component's
own public prop or `#[Computed]` of the same name wins, so its value goes
instead, or nothing does if the prop did not change. The device keeps a key
only where its component has a public prop of that name.

`headers` adds plain HTTP headers to every answer from an Asgard endpoint:
the handshake, renders, calls, uploads, routes, the stream and the errors
they give.

```php
'headers' => [
    'X-Server-Region' => 'eu-west',
],
```

A value is a string or a number, or a callable as above. A callable that
gives null leaves the header out. Names Asgard sets, signs or that a client
reads are refused with an exception: anything starting `Content-`, `Asgard-`
or `X-Asgard-`, and `Allow`, `Cache-Control`, `Connection`, `Keep-Alive`,
`Set-Cookie`, `Signature`, `Signature-Input`, `Trailer`, `Transfer-Encoding`,
`Upgrade` and `X-Accel-Buffering`. So is a value with a line break.

These headers are not encrypted and the response signature does not cover
them, so only TLS protects them. Do not put anything in them that the
encryption is meant to protect.

A fragment pushed on the stream carries no delta, so `state` and computed
values do not reach it. Only the headers do.

### State in every answer

Every answer from render, call and upload carries a `delta`, the state the
device should take on. The device applies it, then paints the fragments.
The server renders before it works out the delta, so the two always match.

- A `#[Server]` call, an upload or an action sends the props the method or
  the render changed, every `#[Computed]` value and the `asgard.state` keys.
- A plain render, a batch or a server screen sends only what the device now
  holds wrongly: a prop that changed after hydration (in `checkClientCache()`
  or a region method, say), and a computed value or `asgard.state` key the
  device sent with a different value. Most renders change nothing, so their
  delta is `{}`.

A prop counts as changed when its `serialize()` form differs from what
hydration left. A route string that hydration turned into an int is not a
change.

### Read-only state

The device can send some of its state as read-only. Its `#[ServerReadOnly]`
props and its own computed values go in the request's `read_only` list. The
server can read them like any other state, but they never come back in a
delta.

If a method or a render changes one, that is a bug in the app. With
`app.debug` on the answer is a 500 `read_only_changed` that names the prop
and the method. Otherwise the key is dropped from the delta and logged as a
warning, and the rest of the answer goes out as usual.

A server `#[Computed]` value with a read-only name is left out when it
matches what the device sent, and counts as a change when it does not. An
`asgard.state` key never overwrites a read-only key of the same name.

### Redrawing other regions

Inside a `#[ServerAction]` or `#[Upload]` method, `$this->refresh()` says which
other regions need drawing again. There are three cases.

**Same component, inline.** Name one or more regions of this component:

```php
#[ServerAction]
public function bump(): void
{
    Cache::increment('taps');

    $this->refresh('greeting');
}
```

The component is already hydrated with the request's state, so after the
method runs those regions are rendered too, as the request's user, and go
back in the same answer under `fragments`, next to the action's own region.
A region named twice is rendered once. Only the instance on the phone that
sent the request paints them, and only if it drew those regions.

**Other components on this phone, a hint.** Name a class or an address, and
maybe one region. Leave the region out to mean all of them:

```php
$this->refresh(InboxScreen::class, 'unread');
$this->refresh('app.native.screens.inbox');
```

The server does not have that component's state, so it does not render it.
The answer carries a hint, and every live instance of it on the phone that
drew the region fetches it again with its own state. One on a screen lower in
the stack fetches when that screen is drawn again. The address and region are
checked as `Asgard::push()` checks them, and an unknown one throws.

**Other phones.** Use `Asgard::push()` or `Asgard::to()`, as before.
`refresh()` only reaches the phone that sent the request.

The device can also choose what a `#[Server]` call or an upload redraws,
with `#[Server(render: true)]`, `render: 'stats'` or `render: ['stats',
'chart']` on its side. It sends those names in the request's `regions`. The
server renders them after the method, together with anything `refresh()`
asked for, each region once.

A name that is a region of this component always means that region. An
answer renders at most 16 inline regions and carries at most 16 hints.

### Region cache

A client can keep a copy of every region it is sent, so a screen that opens
again paints at once instead of showing a placeholder. It then asks the
server as usual, and says which copies it holds. The server decides whether
they are still good.

Every fragment the server sends carries `made_at`, the server's time in Unix
milliseconds when it was rendered, and `max_age`, the longest in seconds the
server lets a client treat it as fresh. `max_age` is left out when the server
sets no limit. The client keeps a copy for the shorter of its own TTL and
`max_age`.

```php
// config/asgard.php
'cache' => [
    'max_age' => 300,  // null (the default) means no server limit
    'grace' => 30,     // the default
],

#[Region('stats', maxAge: 60)]      // this region's own limit
#[Region('balance', maxAge: 0)]     // never cached
#[Screen('/promos/{id}', maxAge: 120)]
```

A copy is fresh when its age is under the shorter of the client's TTL and the
region's max age. A `made_at` up to a minute in the future still counts, for
clock skew. For a fresh region the server does not call the region method at
all. Its part of the answer is `{"cached": true}` and the client keeps what it
shows.

A fresh copy with `grace` seconds or less left is rendered anyway and sent as
a normal fragment, with no `Asgard-Cache` header. The client stores it like
any other, so its copy is renewed before it runs out. The server only ever
answers `{"cached": true}` for a region the client declared in that request.

**A fresh copy wins over every other reason to redraw**: an action on the
region, `refresh()`, a fetch after a push. So once caching is on, a
`#[ServerAction]` that changes what a region shows must say so:

```php
#[ServerAction]
public function like(): void
{
    $this->post->increment('likes');

    $this->forceCacheInvalidation();
}
```

`forceCacheInvalidation()` covers the current answer only. Every region in it
is rendered and sent, and the answer carries the signed header
`Asgard-Cache: invalidate`, which tells the client to replace its copies. It
can be called anywhere in the request: a `#[ServerAction]` or `#[Upload]`
method, a region method before it returns, or the hook below.

`checkClientCache()` runs for each region the client holds a fresh copy of,
before the server skips it. Compare the copy with your data there:

```php
use Native\Asgard\ClientCache;

protected function checkClientCache(string $region, ClientCache $cache): void
{
    if ($this->post->updated_at > $cache->madeAt) {
        $this->forceCacheInvalidation();
    }
}
```

The hook runs for every fresh copy, in its grace window or not.

`ClientCache` has `region`, `madeAt` (a `CarbonImmutable`), `ttl` (seconds),
`age()` (seconds, a float), `remaining()` (seconds of freshness left, negative
once it has run out), `isFresh()` (grace ignored) and `inGrace()`. `$this->clientCache('stats')`
gives the copy for a region anywhere in the request, or null when the client
holds none.

A render the client sends because a push told it to says so, and
`$this->fetchedForPush()` is true. The server does nothing with that by
itself. To let a push always win over a cached copy:

```php
protected function checkClientCache(string $region, ClientCache $cache): void
{
    if ($this->fetchedForPush()) {
        $this->forceCacheInvalidation();
    }
}
```

Shared regions get `made_at` too. A shared fragment is rendered once and kept
in the push store, so every device sent it gets the time it was really made.

## Server screens

A region lives inside a screen the app wrote. A server screen is a whole
screen the server owns, at a route of its own:

```php
use Native\Asgard\Attributes\Screen;
use Native\Asgard\Attributes\ServerAction;
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

    #[ServerAction]
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
- `#[ServerAction]` methods and `Asgard::push()` work as on any component.
  `#[Region]` methods do not: a screen has one body.

### Chrome

`chrome()` returns a `Chrome`, the array form of it, or null for none. It
travels as its own part of the answer and is checked on its own. Chrome is
never accepted inside the body, so `Node::make('top_bar')` or a `<top-bar>`
tag in a screen view is refused. A chrome member the package does not know
is dropped rather than sent.

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
Asgard::push('home');                     // every subscribed region
Asgard::push(HomeScreen::class, 'stats');           // just the stats region
```

```sh
php artisan asgard:push home stats
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
skip the round trip. It is rendered once per address, region, state (every
key the client sent, whether a property takes it or not), version and set of
element types the client advertised, by the first stream that needs it, and kept in the push store for
60 seconds for every other stream to reuse. Each stream still seals it for its
own session.

The cache key has no user in it, so a shared region renders on the stream with
nobody signed in: `auth()`, the default guard and `$request->user()` all see a
guest. A shared region must not depend on the user, on secrets or on request
headers (and must not name a guard of its own to get round that). Because it holds nothing private,
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
| `poll_ms` | `500` | How often each stream reads the push store. Polls run on a fixed schedule, so the work a poll does comes out of the wait before the next. A poll that runs long skips to the next tick. `ASGARD_STREAM_POLL_MS`. |
| `patch_log` | `256` | Patches kept per region counter for streams to send on (see "Patches"). 0 turns patches into signals. `ASGARD_STREAM_PATCH_LOG`. |
| `patch_wait_ms` | `250` | How long a stream waits for the newest patch's log entry, which is written just after its counter moves, before it signals the region instead. `ASGARD_STREAM_PATCH_WAIT_MS`. |

### Patches

A push makes every device fetch the whole region again, or sends the whole
fragment for a shared region. When all that changed is a few items added to a
long list, that is a lot of work for little. A patch sends only the change.

```php
Asgard::patch(Firehose::class, 'feed')->prepend($node, into: 'items', keep: 1000);
Asgard::patch(Firehose::class, 'feed')->prepend([$a, $b], into: 'items');
Asgard::patch(Firehose::class, 'feed')->append($node, into: 'items', keep: 500);
Asgard::patch(Firehose::class, 'feed')->remove(into: 'items', keys: [42, 43]);

Asgard::to('promo.1')->patch(PromoScreen::class)->prepend($node, into: 'items');
```

`into` is the `key` of a node in the region, set with `->key('items')` or
`key="items"` in a Blade region. The device changes that node's children:

- `prepend` puts the nodes before its first child and `append` after its
  last. With `keep`, the device then keeps that many children at most,
  dropping from the far end.
- `remove` drops the children with those keys. Keys it does not have are
  ignored.

The nodes may be a `Node`, an array node, a list of them or a Blade region
view. A view renders on a fresh component with no state, so pass it what it
needs. They are checked exactly like a fragment's nodes: media URLs are made
absolute, callbacks are checked and a tap on a patched node calls its method
as usual. A bad node throws `InvalidFragment` when you call the method,
before anything is sent. A patch takes 1 to 100 nodes, a remove 1 to 1,000
keys, and `keep` is 1 to 100,000. Anything else throws
`InvalidArgumentException`.

The region can be left out for a server screen or a component with only one
region. Each call is one patch and bumps the region's version as
`Asgard::push($component, $region)` does. `Asgard::to()` sends the patch only
to subscriptions in those channels.

**You must change the data too.** A patch only changes what devices already
have on screen. A device that opens the region later, reconnects, or misses
a patch fetches the whole region and gets whatever your region method
renders. So save the new item first, then patch, and make sure a full render
shows the same list the patches build: the same order, the same keys and the
same `keep` limit.

Each patch is rendered once, when you call it, and kept in the push store's
patch log for up to 60 seconds. Every stream wraps it and seals it for its own
session. When a stream polls, it sends one `patch` event per patch since its
last event, in order. Nothing is merged. With `poll_ms` at 16 and 60 patches a
second, that is about one patch a poll.

A stream falls back to the usual signal (or fragment, for a shared region)
whenever it cannot be sure the patches tell the whole story:

- a plain push to the region, its component or a channel it is in landed
  between them;
- it is further behind than the log keeps (`patch_log`), or an entry expired;
- the newest patch's entry is still not written after `patch_wait_ms`;
- a patch to everyone and a channel patch landed in the same poll, so their
  order is unknown;
- a patch uses an element type its client did not advertise.

On connect, a device a few patches behind (after the stream's lifetime ran
out, say) gets those patches, when the log still holds every one since the
version it sent. Otherwise it gets the signal or fragment. The device also
fetches the region when a patch's `from` is not the version it holds, so a
lost patch can never leave a wrong list on screen.

## Sessions and security

The app opens a session with a plain `POST {base}/_asgard/session` carrying
its key, the extra element types it can draw, and an ephemeral P-256 public
key. The server answers with its own public key and a session id, and both
sides derive the session secret with ECDH and HKDF. The secret is never
sent. Sessions live in a cache store and expire after `lifetime` seconds
without a request.

```json
→ {"key":"<ASGARD_KEY>","protocol":1,"accepts":["tree/json"],"elements":["list_item","toggle"],"client_public":"<base64url point>"}
← {"session":"<id>","server_public":"<base64url point>","expires_in":1800,"encoding":"tree/json"}
```

`elements` is optional. It is a list of up to 256 distinct snake_case type
names, kept with the session. A malformed list is a 422. Chrome types and
`server_region` in it are dropped, since a fragment can never use them.

Render, call, upload, stream and route list requests then carry
`Asgard-Session-Id`, an `aes128gcm` body (RFC 8188), `Content-Digest`
(RFC 9530) and an `hmac-sha256` signature under the label `asgard`
(RFC 9421). Server actions, calls and uploads also sign a nonce, and a nonce
is refused the second time. A call or upload without a nonce gets a plain
403 `nonce_required` before its body is decrypted. The `SealAsgardMessages` middleware checks all of
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
compressed. Neither is an answer whose `delta` holds anything, since a delta
can mix the server's values with text the device chose. So a render or an
action is gzipped only when its delta is `{}`, and a call or an upload never
is.
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

**Endpoint:** `{base}/_asgard/render`. The prefix is `asgard.path`, so the
client's base URL (the protocol's `{base}`) is the app's URL plus that
prefix. The endpoints below it are `session`, `render`, `call`, `upload`,
`stream` and `routes`. Every one but `session` takes two forms:

- `QUERY` with a JSON body.
- `POST` with header `X-Asgard-Method: QUERY`. A POST without it gets 405.

`session` takes only a plain POST. Any other method, on any endpoint, gets
the plain, unsigned `405 {"error":"method_not_allowed",…}`.

The route sits outside the `web` group: no session, no CSRF, no cookies. Add
throttling or auth with `asgard.middleware`. `php artisan serve` cannot do
QUERY, so use the POST form there.

**Request headers:** `Content-Type: application/json`,
`Accept: application/json`, `Asgard-Protocol: 1`.

**Request body:**

```json
{"protocol":1,"component":"app.native.screens.home","regions":["greeting"],"state":{"name":"Simon","platform":"ios"},
 "read_only":["platform"]}
```

`read_only` is optional. It lists the keys of `state` the server may read
but never change. Each one must be a key of `state`, named once. Anything
else is a 422. Call, upload and stream subscriptions take it too.

`regions` is a list of distinct region names, even for one region. A body
with `region` instead is a 422. A device that wants several regions of one
component and state names them all in one request:

```json
{"protocol":1,"component":"app.native.screens.home","regions":["greeting","stats"],"state":{"name":"Simon","platform":"ios"}}
```

A request may name at most `asgard.max_regions` regions (16 by default,
`ASGARD_MAX_REGIONS`). One over the limit gets 422
`{"error":"too_many_regions",…}` and nothing in it is rendered. The client
can split the regions across smaller requests.

An action request adds `action` and names exactly one region, the action's
own, in `regions`. Any other count is a 422. `call` and `args` are both
required. `target` may be left out or set to `"server"`. Any other value,
including `"client"`, is a 422.

```json
{"protocol":1,"component":"app.native.screens.home","regions":["greeting"],"state":{"name":"Simon","platform":"ios"},
 "action":{"call":"wave","args":[1]}}
```

A server screen asks for `"regions":["screen"]`.

A render or action request may carry `cache`, the copies of regions of this
component the client holds, keyed by region name (`screen` for a server
screen). `made_at` is the server's own value from when the copy was made.
`ttl` is the client's effective TTL in seconds:

```json
{"protocol":1,"component":"app.native.screens.home","regions":["stats"],"state":{…},
 "cache":{"stats":{"made_at":1763150000123,"ttl":300}}}
```

`cache` must be an object of at most 64 entries. Each key is a region name and
each entry has exactly `made_at` (a positive integer) and `ttl` (an integer
from 0 to 31536000). Anything else is a 422. Names that are not regions of
the component are ignored.

A render request sent because a push told the client to fetch adds
`"reason":"push"`. Any other value is a 422. The server does not act on it by
itself; `fetchedForPush()` exposes it.

`component` is the address the client component named with
`#[EntangleWith]`, or its FQCN in dot notation with each segment
snake-cased. There is no `platform` member: a client sends what it wants the
server to know about itself in `state`. One that still sends `platform` has it
ignored, as any unknown member is. A malformed request gets 422
`{"error":"invalid_request","message":"…"}`.

**Response 200:** a `delta` and a `fragments` map with one part per region,
keyed by region, in the order asked. There is nothing else at the top level
but `protocol`, and `refresh` on an action answer. `delta` is always an
object, `{}` when there is nothing to send. It is described under "State in
every answer" above.

```json
{"protocol":1,"delta":{},"fragments":{
 "greeting":{"version":"…","made_at":1763150000123,"max_age":60,
  "fragment":{"type":"column","class":"p-4 gap-2","children":[{"type":"text","props":{"text":"Hello"}}]}}}}
```

A part has the region's `version`, `made_at` (Unix milliseconds when it was
rendered), `max_age` (seconds) and `fragment`. A server screen's part has
`chrome` too. `max_age` is left out when the server sets no limit. The parts
of a call or upload answer have the same shape.

When the client declared a copy of the region in this request, and it has
more than `asgard.cache.grace` seconds of freshness left, the region is not
rendered and its part is only `{"cached":true}`. A cached part has no
`version`, `made_at`, `max_age` or `chrome`, since the client's copy keeps the
ones it came with:

```json
{"protocol":1,"delta":{},"fragments":{"greeting":{"version":"…","made_at":1763150000123,"fragment":{…}},"stats":{"cached":true}}}
```

A copy inside the grace window is rendered and sent as a normal part. A
region the request did not declare is never answered with `cached`.

When the request forced invalidation, every region is rendered and the
response carries `Asgard-Cache: invalidate`. The response signature covers it,
right after `asgard-protocol`, whenever it is present. A response that
carries the header without covering it does not verify. Calls and uploads
can carry the header too, even with no fragments.

An action answer always has a part for the action's own region, and a delta
shaped like a call's. When the method called `refresh()`, the other regions
it rendered are more parts, and `refresh` carries the hints. `refresh` is
left out when empty:

```json
{"protocol":1,"delta":{"count":3},"fragments":{
 "stats":{"version":"…","made_at":1763150000123,"fragment":{…}},
 "greeting":{"version":"q3V0bG9yZW1pcHN1","made_at":1763150000123,"fragment":{…}}},
 "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
```

An answer is gzipped only when its delta is `{}` and every region in it
allows it. An answer that includes a `compress: false` region, or carries
anything in its delta, is sent as it is.

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
| 403 | `{"error":"nonce_required"}` | An action whose signature has no nonce. Sealed, since only the decrypted body says it is an action. |
| 404 | `{"error":"unknown_component"}` | Unknown component, or a region it doesn't declare (any one of `regions`). Same body for both. |
| 404 | `{"error":"unknown_action"}` | `action.call` is not a callable `#[ServerAction]` on the component, or the arg count doesn't fit it. |
| 405 | `{"error":"method_not_allowed",…}` | POST without `X-Asgard-Method: QUERY`, or a method other than QUERY and POST. Plain, unsigned. |
| 419 | `{"error":"session_expired",…}` | No session, or the server no longer knows it. Plain, unsigned. Handshake again and retry once. |
| 422 | `{"error":"invalid_request","message":…}` | Bad body, `region` instead of `regions`, an action that does not name exactly one region, bad `action` shape, state that doesn't fit a prop's type, or an arg that doesn't fit its param's type. |
| 422 | `{"error":"too_many_regions","message":…}` | `regions` names more than `asgard.max_regions`. Nothing is rendered. |
| 500 | `{"error":"invalid_fragment"}` | The region returned a bad fragment, including one with a type the client did not list. The message is included in debug. |
| 500 | `{"error":"invalid_delta"}` | The action or the render left a changed prop, or a `#[Computed]` gave a value, that is not a plain JSON value. The message is included in debug. |
| 500 | `{"error":"read_only_changed"}` | The action or the render changed a key the request listed in `read_only`. Only with `app.debug` on, and the message names the key and the method. Otherwise the key is dropped from the delta and logged. |

### Call

**Endpoint:** `{base}/_asgard/call`, in the same two forms as render. Like an
action, its signature must carry a nonce.

**Request body:** the component, the `#[Server]` method, its args by position,
and the device's state after its own body ran, plus optional `read_only`,
`regions` and `cache` as on render. Nothing else is allowed. A
`platform` member from an older client is refused like any unknown member.

```json
{"protocol":1,"component":"app.native.screens.home","method":"roll","args":[],"state":{"rolling":true,"platform":"ios"},
 "read_only":["platform"],"regions":["stats"]}
```

`regions` names the regions to draw again once the method has run. It is a
list of distinct region names of this component, checked as render checks
them, and may be empty. A name that is not a region of the component is a
422 `invalid_request`, and more than `asgard.max_regions` is a 422
`too_many_regions`. Either way the method does not run.

**Response 200:** the props the method changed, then every `#[Computed]`
value, then the keys from `asgard.state`. `delta` is always an object, `{}`
when there is nothing to send. It is never gzipped, since it can mix the server's
values with text the device chose.

```json
{"protocol":1,"delta":{"result":4}}
```

A request with `regions`, or a method that called `refresh()`, adds
`fragments`, shaped as on an action's answer: the regions the request named,
then the ones `refresh()` added, each once. Hints for other components go in
`refresh`. A region the request's `cache` holds fresh is `{"cached":true}`
there. The regions are rendered before the delta is worked out, so a prop a
region changes is in it too. The answer is still never gzipped.

```json
{"protocol":1,"delta":{"result":4},"fragments":{"stats":{"version":"…","made_at":1763150000123,"fragment":{…}}}}
```

A key in `read_only` is never in the delta. One the method changed is a 500
`read_only_changed` with `app.debug` on, and dropped and logged otherwise.

**Errors:** as render, except:

| Status | Body | When |
| --- | --- | --- |
| 403 | `{"error":"nonce_required",…}` | The signature has no nonce. Plain and unsigned, sent before the body is decrypted. |
| 404 | `{"error":"unknown_component"}` | The address is not registered. |
| 404 | `{"error":"unknown_method"}` | `method` is not a callable `#[ServerAction]` method on the component, or the arg count doesn't fit it. |
| 422 | `{"error":"invalid_request","message":…}` | Bad body, an unknown field, a bad `read_only`, a name in `regions` that is not a region of the component, state that doesn't fit a prop's type, or an arg that doesn't fit its param's type. |
| 422 | `{"error":"too_many_regions","message":…}` | `regions` names more than `asgard.max_regions`. The method does not run. |
| 500 | `{"error":"invalid_delta"}` | The method or a region it drew left a changed prop, or a `#[Computed]` gave a value, that is not a plain JSON value. The message is included in debug. |
| 500 | `{"error":"read_only_changed"}` | The method changed a key the request listed in `read_only`. Only with `app.debug` on. |

### Upload

**Endpoint:** `{base}/_asgard/upload`, in the same two forms as render. Its
signature must carry a nonce.

**Request body:** a frame, not JSON. It is sealed like every other body, with
the same `Content-Type: application/json` on the wire, and never gzipped.

```
uint32, big-endian   N, the length of the header (at most 1 MiB)
N bytes              the header, a JSON object
the rest             the file, exactly `size` bytes
```

```json
{"protocol":1,"component":"app.native.screens.home","method":"savePhoto","args":[],"state":{"name":"Simon","platform":"ios"},"filename":"IMG_0001.jpg","mime":"image/jpeg","size":2483113}
```

The header may carry `read_only`, `regions` and `cache` as a call can. `filename` and `mime` are what
the device says, and only informational.
A frame was picked over JSON with the file in base64, which would make every
upload a third bigger and need it decoded in memory as well.

**Response 200:** as a call's.

**Errors:** as a call's, plus:

| Status | Body | When |
| --- | --- | --- |
| 404 | `{"error":"unknown_method"}` | `method` is not a callable `#[Upload]` method on the component, or the arg count doesn't fit it. |
| 413 | `{"error":"too_large",…}` | The file is over the method's `maxSize` or `asgard.uploads.max_size`. Plain and unsigned when it is caught before decrypting. |
| 415 | `{"error":"unsupported_type",…}` | The sniffed type is not in the method's `mimes`. |
| 422 | `{"error":"invalid_request","message":…}` | A bad frame, a `size` that is not the number of bytes sent, an empty file, or anything a call refuses. |

### Stream

**Endpoint:** `{base}/_asgard/stream`, in the same two forms as render
(`QUERY`, or `POST` with `X-Asgard-Method: QUERY`).

**Request body:** 1 to 16 subscriptions, one per
component instance on screen. No `action`.

```json
{"protocol":1,"subscriptions":[
 {"id":"3f9a0c1d2e4b5a6c","component":"app.native.screens.home","regions":["greeting","stats"],
  "state":{"name":"Simon","platform":"ios"},"versions":{"greeting":"q3V0bG9yZW1pcHN1"}}]}
```

- `id` is the device's own name for the subscription: 1 to 32 characters of
  `A-Z a-z 0-9 _ -`, unique within the request. Events name it.
- `regions` is 1 to 16 distinct region names, checked as render checks one.
- `state` is checked and hydrated as render's is, separately per subscription.
- `read_only` is optional and checked as render checks it. A stream sends no
  delta, so it changes nothing else.
- `versions` is optional. It maps a region to the `version` token the device
  holds for it, from a render answer or an earlier event.
- Other members, at the top level or in a subscription, are ignored. Only
  `action` is refused.

Everything is checked before the stream starts, and failures are the same JSON
errors as render: 405, 422 (bad body, bad subscriptions, an `action` key, state
that doesn't fit), 404 `unknown_component` (unknown component, or any region
it doesn't declare, in any subscription), 500 `invalid_fragment`.

Render answers carry the token too, in each part:
`{"protocol":1,"fragments":{"greeting":{"version":"q3V0bG9yZW1pcHN1","made_at":1763150000123,"fragment":{…}}}}`.
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
for a signal, plus `made_at`, `max_age` and `fragment` (and `chrome` for a
server screen) for a shared region's fragment event. `made_at` is when the
shared render was made, the same for every device it is sent to. `sig` is HMAC-SHA256 with
`sig_s` over `asgard/1 event`, the stream request's signature in base64, the
event id, the event name, `enc` and `body`, one per line. An event only
verifies on the stream it was sent on.

- On connect, one event per region whose current token differs from the one
  the device sent (or that it sent none for), in the order asked for. A
  region the patch log can bring from the device's version to the current
  one gets its `patch` events instead.
- After that, one event for each region a push touched, in every
  subscription it touched.
- The event is `signal` for a region that is not shared. Its body is
  `{"protocol":1,"subscription":…,"region":…,"version":…}`, never compressed,
  and it is signed with `signal` as the event name. The device fetches the
  region through render unless it already holds that version.
- The event is `fragment` for a shared region, with the fragment (and chrome)
  in the body.
- The event is `patch` for a region changed by `Asgard::patch()`, when the
  stream can send every patch since its last event (see "Patches"). Its body is
  `{"protocol":1,"subscription":…,"region":…,"from":…,"version":…,"patch":{"op":"prepend","target":"items","nodes":[…],"keep":1000}}`,
  or `{"op":"append",…}` or `{"op":"remove","target":"items","keys":["42"]}`.
  `from` is the version before the patch and `version` the one after. It is
  compressed when the region compresses and signed with `patch` as the event
  name. A device that does not hold `from` fetches the region instead.
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
  declared on the component class carries `#[ServerAction]` and not `#[Region]`.
  Methods on `ServerComponent` and on parent classes never count. Params are
  limited to plain JSON types, and each arg is type checked strictly before
  the call (no `"5"` into `int`). The wire `call` is only ever a key into that
  map, and region resolution happens first, so an action never runs for a
  region that doesn't exist.
- A call is only reachable for a method in that same `#[ServerAction]` map.
  The wire `method` is only ever a key into it. A call needs a nonce, and a
  nonce is refused the second time.
- An upload is only reachable for a method that carries `#[Upload]`, passes
  the same checks, and whose first param takes an `UploadedFile`. It needs a
  nonce too. The file's type is sniffed from its bytes, and its device
  filename is never part of a path.
- Fragments are validated before they leave. Each node's type must be a
  core type or one the client listed in `elements` at the handshake (see
  "Element types"). Screen chrome (`top_bar*`, `bottom_nav*`, `bottom_bar`,
  `side_nav*`, `fab`) and `server_region` are rejected even if listed.
  Callback slots must be `{call, args, target?}` objects. Props keys
  starting `_`, `native-` or `native:` are stripped. Node fields the
  package does not know are dropped. Nesting deeper than 64 levels (the
  root counts as 1) is rejected, and so are siblings that share a key.
  Media URLs are made absolute and any scheme but `http:` and `https:` is
  rejected. A `webview` whose `local` (or older `php`) prop is anything but
  `false` or `null` is rejected, because it would load the app's own
  content.
- Chrome members the package does not know are dropped, not sent. Slots and
  navigations in chrome are checked like a fragment's, and refuse unknown
  members.
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

### Conformance suite

The protocol repo has a conformance suite: 80 server cases run over HTTP, and
offline byte vectors. `workbench/app` holds the fixture components its
`conformance/FIXTURES.md` asks for, and `public/index.php` boots Testbench
with them, so a web server can serve this package on its own.

PHP's built-in server answers QUERY with 501, so serve the package with a
real one. With Herd, link the package folder and use its `.test` host:

```sh
herd link asgard
node ../asgard-protocol/conformance/runner/run.mjs \
    --server http://asgard.test/_asgard --key conformance-key
```

Add `--case name,prefix-*` to run some cases only. The key comes from
`testbench.yaml`. The offline vectors also have a checker that uses this
package's own crypto classes:

```sh
php conformance/check-vectors.php ../asgard-protocol/conformance/vectors/vectors.json
```
