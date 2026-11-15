<?php

namespace Native\Asgard\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Native\Asgard\ClientCache;
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
    public const CACHE_HEADER = 'Asgard-Cache';

    public const CACHE_INVALIDATE = 'invalidate';

    /**
     * One region's part of an answer: when it was made, its max age, its
     * fragment, and its chrome when the component is a server screen. A
     * JsonResponse is the error to send instead.
     *
     * `made_at` is taken before the region method runs, so it is never
     * later than the data the fragment was built from.
     *
     * @return array{made_at: int, max_age?: int, fragment: mixed, chrome?: object}|JsonResponse
     */
    protected function renderPart(ServerComponent $component, ComponentMetadata $metadata, string $region): array|JsonResponse
    {
        $method = $metadata->regions[$region];
        $component->setAsgardContext($region);

        $part = $metadata->stamp($region, ClientCache::nowMs());

        $result = $this->container->call([$component, $method]);

        try {
            $part['fragment'] = FragmentValidator::validate($this->views->resolve($result, $component), $this->elements());
        } catch (InvalidFragment $e) {
            return $this->invalidFragment($e, $component::class, $method);
        }

        // A screen's chrome travels as its own part, checked on its own.
        if ($component instanceof ServerScreen) {
            try {
                $part['chrome'] = (object) ChromeValidator::validate($this->views->chrome($component->chrome(), $component), $this->elements());
            } catch (InvalidFragment $e) {
                return $this->invalidFragment($e, $component::class, 'chrome');
            }
        }

        return $part;
    }

    /**
     * Several regions, each as `{version?, made_at, max_age?, fragment,
     * chrome?}`, keyed by region in the order given. Tokens come from one
     * read of the counters, taken before any of them renders. `$tokens`
     * holds any read earlier, before an action ran.
     *
     * A region whose client copy is fresh is not rendered at all. Its entry
     * is `{"cached": true}`. Every fresh region goes through
     * checkClientCache() before anything renders. When anything forces
     * invalidation, including a region method on its way, every region is
     * rendered after all.
     *
     * @param  list<string>  $regions
     * @param  list<string>  $channels
     * @param  array<string, string|null>  $tokens
     * @return array<string, array<string, mixed>>|JsonResponse
     */
    protected function renderParts(ServerComponent $component, ComponentMetadata $metadata, string $address, array $regions, array $channels, array $tokens = []): array|JsonResponse
    {
        $missing = array_values(array_diff($regions, array_keys($tokens)));

        if ($missing !== []) {
            $tokens += $this->tokens($address, $missing, $channels);
        }

        $cached = [];
        foreach ($regions as $region) {
            if ($component->asgardServesFromCache($region)) {
                $cached[$region] = true;
            }
        }

        $parts = [];

        // A region method that forces invalidation brings back the regions
        // already passed over, so the loop runs at most twice.
        do {
            if ($component->asgardInvalidatesCache()) {
                $cached = [];
            }

            foreach ($regions as $region) {
                if (isset($cached[$region]) || isset($parts[$region])) {
                    continue;
                }

                $part = $this->renderPart($component, $metadata, $region);

                if ($part instanceof JsonResponse) {
                    return $part;
                }

                $parts[$region] = [
                    ...($tokens[$region] === null ? [] : ['version' => $tokens[$region]]),
                    ...$part,
                ];
            }
        } while ($cached !== [] && $component->asgardInvalidatesCache());

        $answer = [];
        foreach ($regions as $region) {
            $answer[$region] = $parts[$region] ?? ['cached' => true];
        }

        return $answer;
    }

    /**
     * The answer, with `Asgard-Cache: invalidate` when something forced
     * invalidation. SealAsgardMessages signs that header.
     *
     * @param  array<string, mixed>  $answer
     */
    protected function respond(ServerComponent $component, array $answer): JsonResponse
    {
        $response = response()->json($answer, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($component->asgardInvalidatesCache()) {
            $response->headers->set(self::CACHE_HEADER, self::CACHE_INVALIDATE);
        }

        return $response;
    }

    /**
     * What refresh() asked for inside the method that just ran: the other
     * regions of this component rendered now, and hints for other
     * components.
     *
     * @param  list<string>  $channels
     * @return array{fragments: array<string, array<string, mixed>>, refresh: list<array{component: string, region?: string}>}|JsonResponse
     */
    protected function refreshed(ServerComponent $component, ComponentMetadata $metadata, string $address, array $channels): array|JsonResponse
    {
        $asked = $component->asgardRefreshes();
        $regions = $asked['regions'];

        $fragments = $regions === []
            ? []
            : $this->renderParts($component, $metadata, $address, $regions, $channels);

        if ($fragments instanceof JsonResponse) {
            return $fragments;
        }

        return ['fragments' => $fragments, 'refresh' => $asked['hints']];
    }

    /**
     * Each region's version token (Asgard protocol §8.1), from one read of the
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
