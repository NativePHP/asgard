<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\ServerComponent;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\ConfiguredState;
use Native\Asgard\Support\JsonValue;
use ReflectionObject;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * The state every answer sends back in `delta`: render, action, call and
 * upload alike. A component's own prop or computed wins over a configured
 * key of the same name. Needs RendersRegions and the ReadsAsgardRequests
 * helpers.
 *
 * A full delta (a call, an upload, an action) is the props the method or
 * the render changed, then every #[Computed] value, then the app-wide keys
 * from `asgard.state`.
 *
 * A plain render's delta is only what the device now holds wrongly: the
 * props that changed since hydration, and the computed values and
 * configured keys the device sent with a different value. Most renders
 * change nothing, so their delta is `{}` and the answer can still be
 * compressed (§7.4).
 *
 * Keys the device sent in `read_only` are never put in a delta. One that
 * the server changed is a bug in the app: in debug it is a 500
 * read_only_changed, otherwise it is dropped and logged as a warning.
 *
 *     ← {"protocol":1,"delta":{"roll":4},
 *        "fragments":{"stats":{"version":"…","made_at":1763150000123,"fragment":{…}}},
 *        "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
 */
trait AnswersWithDelta
{
    /**
     * Build a call's or upload's answer once the method has run: the
     * regions the request named and the ones refresh() asked for, rendered
     * together, then the delta. `$before` is fingerprints() from straight
     * after hydration. `$tokens` were read for `$regions` before the method
     * ran.
     *
     * @param  list<string>  $channels
     * @param  array<string, string>  $before
     * @param  array<string, mixed>  $sent
     * @param  list<string>  $readOnly
     * @param  list<string>  $regions
     * @param  array<string, string|null>  $tokens
     */
    protected function answer(ServerComponent $component, ComponentMetadata $metadata, string $address, string $method, array $channels, array $before, array $sent, array $readOnly, array $regions = [], array $tokens = []): JsonResponse
    {
        $asked = $component->asgardRefreshes();
        $regions = array_values(array_unique([...$regions, ...$asked['regions']]));

        $fragments = $regions === []
            ? []
            : $this->renderParts($component, $metadata, $address, $regions, $channels, $tokens);

        if ($fragments instanceof JsonResponse) {
            return $fragments;
        }

        $delta = $this->stateDelta($component, $metadata, $before, $sent, $readOnly, true, $component::class.'::'.$method.'()');

        if ($delta instanceof JsonResponse) {
            return $delta;
        }

        $answer = ['protocol' => self::PROTOCOL, 'delta' => (object) $delta];

        if ($fragments !== []) {
            $answer['fragments'] = $fragments;
        }

        if ($asked['hints'] !== []) {
            $answer['refresh'] = $asked['hints'];
        }

        // Never compressed, fragments or not: the delta can mix the
        // server's values with text the device chose (§7.4).

        return $this->respond($component, $answer);
    }

    /**
     * A 422 for the first name in a call's or upload's `regions` that is
     * not a region of this component, or null when they all are.
     *
     * @param  list<string>  $regions
     */
    protected function unknownRegion(ComponentMetadata $metadata, array $regions): ?JsonResponse
    {
        foreach ($regions as $region) {
            if (! isset($metadata->regions[$region])) {
                return $this->error(422, 'invalid_request', "regions names [{$region}], which is not a region of this component.");
            }
        }

        return null;
    }

    /**
     * The delta, or the error to send instead. `$full` is true for a call,
     * an upload or an action, false for a plain render. `$where` names what
     * ran, for errors and logs.
     *
     * @param  array<string, string>  $before
     * @param  array<string, mixed>  $sent
     * @param  list<string>  $readOnly
     * @return array<string, mixed>|JsonResponse
     */
    protected function stateDelta(ServerComponent $component, ComponentMetadata $metadata, array $before, array $sent, array $readOnly, bool $full, string $where): array|JsonResponse
    {
        try {
            $props = $this->delta($component, $before);
            $computed = $this->computed($component, $metadata);
        } catch (UnexpectedValueException $e) {
            report($e);

            return config('app.debug')
                ? $this->error(500, 'invalid_delta', $where.' left a value that cannot be sent. '.$e->getMessage())
                : response()->json(['error' => 'invalid_delta'], 500);
        } catch (InvalidRequest $e) {
            // As if the method itself had thrown it.
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        // Config mistakes throw, as they would anywhere else. A read-only
        // key is never overwritten by a configured one.
        $configured = array_diff_key(ConfiguredState::resolve(), $this->properties($component), array_flip($readOnly));

        if (! $full) {
            $computed = $this->stale($computed, $sent);
            $configured = $this->stale($configured, $sent);
        }

        $changed = [];

        foreach ($readOnly as $key) {
            if (array_key_exists($key, $props)) {
                $changed[] = $key;
                unset($props[$key]);
            }

            if (array_key_exists($key, $computed)) {
                if ($computed[$key] !== $sent[$key]) {
                    $changed[] = $key;
                }

                unset($computed[$key]);
            }
        }

        if ($changed !== []) {
            $keys = implode(', ', array_map(fn ($key) => "[{$key}]", $changed));
            $message = "{$where} changed {$keys}, which the device sent as read-only.";

            if (config('app.debug')) {
                return $this->error(500, 'read_only_changed', $message);
            }

            Log::warning('Asgard dropped read-only state the server changed. '.$message, [
                'component' => $component::class,
                'keys' => $changed,
            ]);
        }

        return $props + $computed + $configured;
    }

    /**
     * The values the device sent with a different value. One it did not
     * send has nowhere to go on the device.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    protected function stale(array $values, array $sent): array
    {
        return array_filter(
            $values,
            fn ($value, $key) => array_key_exists($key, $sent) && $sent[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
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
     * and the render left them. One that throws throws as the method would
     * have.
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
