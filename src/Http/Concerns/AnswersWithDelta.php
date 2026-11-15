<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\ConfiguredState;
use Native\Asgard\Support\JsonValue;
use ReflectionObject;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * The answer to a `#[Server]` call or an upload: the props the method
 * changed, then every #[Computed] value, then the app-wide keys from
 * `asgard.state`, plus any regions and hints it asked for with refresh().
 * A component's own prop or computed wins over a configured key of the
 * same name.
 * Shared by the call and upload endpoints. Needs RendersRegions and the
 * ReadsAsgardRequests helpers.
 *
 *     ← {"protocol":1,"delta":{"roll":4},
 *        "fragments":{"stats":{"version":"…","made_at":1763150000123,"fragment":{…}}},
 *        "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
 */
trait AnswersWithDelta
{
    /**
     * Build the answer once the method has run. `$before` is
     * fingerprints() from straight after hydration.
     *
     * @param  list<string>  $channels
     * @param  array<string, string>  $before
     */
    protected function answer(ServerComponent $component, ComponentMetadata $metadata, string $address, string $method, array $channels, array $before): JsonResponse
    {
        try {
            $delta = $this->delta($component, $before);
            $delta += $this->computed($component, $metadata);
        } catch (UnexpectedValueException $e) {
            report($e);

            return config('app.debug')
                ? $this->error(500, 'invalid_delta', $component::class.'::'.$method.'() left a value that cannot be sent. '.$e->getMessage())
                : response()->json(['error' => 'invalid_delta'], 500);
        } catch (InvalidRequest $e) {
            // As if the method itself had thrown it.
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Config mistakes throw, as they would anywhere else.
        $delta += array_diff_key(ConfiguredState::resolve(), $this->properties($component));

        $answer = ['protocol' => self::PROTOCOL, 'delta' => (object) $delta];

        $more = $this->refreshed($component, $metadata, $address, $channels);

        if ($more instanceof JsonResponse) {
            return $more;
        }

        if ($more['fragments'] !== []) {
            $answer['fragments'] = $more['fragments'];
        }

        if ($more['refresh'] !== []) {
            $answer['refresh'] = $more['refresh'];
        }

        // Never compressed, fragments or not: the delta can mix the
        // server's values with text the device chose (§7.4).

        return $this->respond($component, $answer);
    }

    /**
     * Every public prop's serialize() form. An unset typed prop is absent,
     * so one the method fills in counts as changed.
     *
     * @return array<string, string>
     */
    protected function fingerprints(ServerComponent $component): array
    {
        $prints = [];

        foreach ($this->properties($component) as $name => $property) {
            if ($property->isInitialized($component)) {
                $prints[$name] = serialize($property->getValue($component));
            }
        }

        return $prints;
    }

    /**
     * The props whose value differs from what hydration left, checked to
     * be plain JSON values.
     *
     * @param  array<string, string>  $before
     * @return array<string, mixed>
     */
    protected function delta(ServerComponent $component, array $before): array
    {
        $delta = [];

        foreach ($this->properties($component) as $name => $property) {
            if (! $property->isInitialized($component)) {
                continue;
            }

            $value = $property->getValue($component);

            if (array_key_exists($name, $before) && $before[$name] === serialize($value)) {
                continue;
            }

            if (! JsonValue::is($value)) {
                throw new UnexpectedValueException("[{$name}] must be null, a bool, a finite number, a UTF-8 string, or an array of those.");
            }

            $delta[$name] = $value;
        }

        return $delta;
    }

    /**
     * Every #[Computed] value, worked out now from the props as the method
     * left them. Sent every time, changed or not. One that throws throws
     * as the method would have.
     *
     * @return array<string, mixed>
     */
    protected function computed(ServerComponent $component, ComponentMetadata $metadata): array
    {
        $values = [];

        foreach ($metadata->computed as $name) {
            $value = $component->{$name}();

            if (! JsonValue::is($value)) {
                throw new UnexpectedValueException("#[Computed] [{$name}] must give null, a bool, a finite number, a UTF-8 string, or an array of those.");
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /** @return array<string, ReflectionProperty> */
    protected function properties(ServerComponent $component): array
    {
        $properties = [];

        foreach ((new ReflectionObject($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $properties[$property->getName()] = $property;
            }
        }

        return $properties;
    }
}
