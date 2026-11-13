<?php

namespace Native\Asgard\Support;

use Native\Asgard\Exceptions\InvalidFragment;

/**
 * Makes every media URL in a fragment or in chrome absolute (protocol §9.6).
 *
 * On the device a relative `src` is an asset the app shipped, and a path
 * starting with `/` is a file on the phone. Neither is what a server author
 * means by `/storage/promo.jpg`, and the device refuses both in a fragment.
 * So relative URLs are resolved here, against `asgard.media_url` when it is
 * set and against `asset()` otherwise, which follows ASSET_URL and the
 * request's scheme and host.
 *
 * - `img/x.png`, `./img/x.png` and `/img/x.png` all become `{base}/img/x.png`.
 * - `//host/x` gets the base's scheme.
 * - `http:` and `https:` URLs pass through.
 * - Any other scheme is refused, `data:` included. Platform support for
 *   `data:` differs, and a fragment is re-sent on every push, so an inline
 *   image costs every redraw instead of being cached once. Serve it from a
 *   URL instead.
 * - An empty string stays empty and means no image.
 *
 * The device fetches these URLs with no Asgard session and none of the
 * user's auth, so private media needs a signed or temporary URL.
 */
final class MediaUrls
{
    /**
     * Prop names that load a URL. `image` and `webview` take `src`,
     * `list_item` takes `leadingAvatar` and `leadingImage`. `src` counts on
     * every type, so a plugin element that loads one is covered too. The
     * device checks the same names.
     */
    public const PROPS = ['src', 'leadingAvatar', 'leadingImage'];

    private const MAX_LENGTH = 2048;

    /** The absolute form of a media URL, or InvalidFragment. */
    public static function resolve(mixed $url, string $path): mixed
    {
        if ($url === null || $url === '') {
            return $url;
        }

        if (! is_string($url)) {
            throw new InvalidFragment("{$path}: a media URL must be a string.");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidFragment("{$path}: a media URL cannot contain control characters.");
        }

        $url = str_replace(' ', '%20', trim($url));

        if (str_starts_with($url, '//')) {
            $url = self::scheme().':'.$url;
        } elseif (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $m) === 1) {
            $scheme = strtolower($m[1]);

            if ($scheme === 'data') {
                throw new InvalidFragment("{$path}: data: URLs are not allowed in a fragment. Serve the image from a URL.");
            }

            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new InvalidFragment("{$path}: [{$scheme}:] URLs are not allowed in a fragment. Use http(s) or a path.");
            }
        } else {
            while (str_starts_with($url, './')) {
                $url = substr($url, 2);
            }

            $url = self::absolute($url);
        }

        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidFragment("{$path}: a media URL is longer than ".self::MAX_LENGTH.' bytes.');
        }

        return $url;
    }

    private static function absolute(string $path): string
    {
        $base = config('asgard.media_url');

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').'/'.ltrim($path, '/');
        }

        return asset(ltrim($path, '/'));
    }

    private static function scheme(): string
    {
        return parse_url(self::absolute(''), PHP_URL_SCHEME) ?: 'https';
    }
}
