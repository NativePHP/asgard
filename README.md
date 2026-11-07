# nativephp/asgard

The server half of Asgard, NativePHP's server-driven UI protocol. A NativePHP
mobile app marks regions with `@server('greeting') … @endserver`, sends its
state here, and paints the native elements this package returns.

The protocol itself is described in `docs/ASGARD-PROTOCOL.md` in mobile-air.
This package implements a v0 walking skeleton of it.

> **TODO: no handshake, sessions, signing or encryption yet.** Protocol §6 and
> §7 are skipped in this slice. Requests are unauthenticated and state is
> unsigned. Treat every region as public, and do not return anything from one
> that you would not put on a public web page.

## Install

```sh
composer require nativephp/asgard
php artisan vendor:publish --tag=asgard-config
```

## Writing a server component

```php
use Native\Asgard\Attributes\Locked;
use Native\Asgard\Attributes\Region;
use Native\Asgard\Node;
use Native\Asgard\ServerComponent;

class HomeScreen extends ServerComponent
{
    public string $name = 'friend';   // hydrated from the client's state

    #[Locked]
    public string $role = 'guest';    // never written by the client

    #[Region('greeting')]
    public function greeting(): Node
    {
        return Node::column(
            Node::text("Hello, {$this->name}"),
            Node::button('Wave')->onPress("wave()"),
        )->class('p-4 gap-2');
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

A region method may return a `Node`, a list of `Node`s, or plain arrays in the
node shape. Region methods are resolved through the container, so they can
type-hint dependencies. `$this->platform()` and `$this->region()` are
available inside them.

### Node builder

`Node::make($type)` then `->key()`, `->props([...])` (merges), `->prop($k, $v)`,
`->class()`, `->children(...)`, `->onPress()`, `->onLongPress()`,
`->onDoubleTap()`, `->onChange()`, `->onSubmit()`. Shortcuts:
`Node::column(...$children)`, `Node::row(...$children)`, `Node::text('Hi')`,
`Node::button('Go')`, `Node::spacer()`, `Node::divider()`.

## Wire contract (v0)

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

`component` is the client component's FQCN in dot notation with each segment
snake-cased. `platform` is `ios` or `android`. A malformed request gets 422
`{"error":"invalid_request","message":"…"}`.

**Response 200:**

```json
{"protocol":1,"region":"greeting","fragment":{"type":"column","class":"p-4 gap-2","children":[{"type":"text","props":{"text":"Hello"}}]}}
```

`fragment` is one node or a list of nodes:
`{type, key?, props?, class?, on_press?, on_long_press?, on_double_tap?, on_change?, on_submit?, children?}`.

**Errors:**

| Status | Body | When |
| --- | --- | --- |
| 404 | `{"error":"unknown_component"}` | Unknown component, or a region it doesn't declare. Same body for both. |
| 405 | `{"error":"method_not_allowed",…}` | POST without `X-Asgard-Method: QUERY`. |
| 422 | `{"error":"invalid_request","message":…}` | Bad body, or state that doesn't fit a prop's type. |
| 500 | `{"error":"invalid_fragment"}` | The region returned a bad fragment. The message is included in debug. |

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
- Fragments are validated before they leave. Screen chrome (`top_bar*`,
  `bottom_nav*`, `bottom_bar`, `side_nav*`, `fab`) and `server_region` are
  rejected. Props keys starting `_`, `native-` or `native:` are stripped.
  Unknown node fields are rejected.

## Tests

```sh
composer test
```
