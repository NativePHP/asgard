<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Native\Asgard\Exceptions\InvalidFragment;
use Native\Asgard\ServerComponent;
use Native\Asgard\ServerScreen;
use Native\Asgard\Support\ChromeValidator;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\PushVersions;
use Throwable;

/**
 * Rendering one or more regions of a component that is already hydrated,
 * shared by the render and call endpoints. Needs $views, $versions and
 * the ReadsAsgardRequests helpers.
 */
trait RendersRegions
{
    /**
     * One region's part of an answer: its fragment, and its chrome when the
     * component is a server screen. A JsonResponse is the error to send
     * instead.
     *
     * @return array{fragment: mixed, chrome?: object}|JsonResponse
     */
    protected function renderPart(ServerComponent $component, ComponentMetadata $metadata, string $region, string $platform): array|JsonResponse
    {
        $method = $metadata->regions[$region];
        $component->setAsgardContext($region, $platform);

        $result = $this->container->call([$component, $method]);

        try {
            $part = ['fragment' => FragmentValidator::validate($this->views->resolve($result, $component))];
        } catch (InvalidFragment $e) {
            return $this->invalidFragment($e, $component::class, $method);
        }

        // A screen's chrome travels as its own part, checked on its own.
        if ($component instanceof ServerScreen) {
            try {
                $part['chrome'] = (object) ChromeValidator::validate($this->views->chrome($component->chrome(), $component));
            } catch (InvalidFragment $e) {
                return $this->invalidFragment($e, $component::class, 'chrome');
            }
        }

        return $part;
    }

    /**
     * Several regions, each as `{version?, fragment, chrome?}`, keyed by
     * region in the order given. Tokens come from one read of the counters,
     * taken before any of them renders.
     *
     * @param  list<string>  $regions
     * @param  list<string>  $channels
     * @return array<string, array<string, mixed>>|JsonResponse
     */
    protected function renderParts(ServerComponent $component, ComponentMetadata $metadata, string $address, array $regions, array $channels, string $platform): array|JsonResponse
    {
        $tokens = $this->tokens($address, $regions, $channels);
        $parts = [];

        foreach ($regions as $region) {
            $part = $this->renderPart($component, $metadata, $region, $platform);

            if ($part instanceof JsonResponse) {
                return $part;
            }

            $parts[$region] = [
                ...($tokens[$region] === null ? [] : ['version' => $tokens[$region]]),
                ...$part,
            ];
        }

        return $parts;
    }

    /**
     * What refresh() asked for inside the method that just ran: the other
     * regions of this component rendered now, and hints for other
     * components. `$skip` is the region the answer already carries.
     *
     * @param  list<string>  $channels
     * @return array{fragments: array<string, array<string, mixed>>, refresh: list<array{component: string, region?: string}>}|JsonResponse
     */
    protected function refreshed(ServerComponent $component, ComponentMetadata $metadata, string $address, array $channels, string $platform, ?string $skip = null): array|JsonResponse
    {
        $asked = $component->asgardRefreshes();
        $regions = array_values(array_filter($asked['regions'], fn (string $region) => $region !== $skip));

        $fragments = $regions === []
            ? []
            : $this->renderParts($component, $metadata, $address, $regions, $channels, $platform);

        if ($fragments instanceof JsonResponse) {
            return $fragments;
        }

        return ['fragments' => $fragments, 'refresh' => $asked['hints']];
    }

    /**
     * Each region's version token (protocol §8.1), from one read of the
     * push counters. The push store being down only costs the tokens.
     *
     * @param  list<string>  $regions
     * @param  list<string>  $channels
     * @return array<string, string|null>
     */
    protected function tokens(string $address, array $regions, array $channels): array
    {
        try {
            $snapshot = $this->versions->snapshot($address, $regions, $channels);
        } catch (Throwable $e) {
            report($e);

            return array_fill_keys($regions, null);
        }

        $tokens = [];
        foreach ($regions as $region) {
            $tokens[$region] = PushVersions::token($address, $region, $channels, $snapshot);
        }

        return $tokens;
    }
}
