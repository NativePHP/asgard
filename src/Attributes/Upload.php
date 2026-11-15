<?php

namespace Native\Asgard\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a public method on a ServerComponent as one a device may send a
 * file to, with `$this->uploadToServer('savePhoto', $path)` in the app.
 *
 *     #[Upload(maxSize: '10M', mimes: ['image/jpeg', 'image/png'])]
 *     public function savePhoto(UploadedFile $file): void {}
 *
 * It is the allowlist for the upload endpoint, kept apart from
 * #[ServerAction]. The method's first parameter must take an
 * Illuminate\Http\UploadedFile. Any others are plain JSON args, checked
 * as a server action's are.
 *
 * maxSize: the largest file this method takes, as bytes or a size such
 *          as '512K', '10M' or '1G' (powers of 1024). It can only lower
 *          `asgard.uploads.max_size`, never raise it. null means that cap.
 * mimes:   the types the file's content must have, sniffed by the server
 *          from the bytes. The device's own claim is ignored. 'image/*'
 *          matches any image type. null or [] takes any type.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Upload
{
    /** @param  list<string>|null  $mimes */
    public function __construct(
        public readonly int|string|null $maxSize = null,
        public readonly ?array $mimes = null,
    ) {}

    /**
     * Bytes from an int or a size string. 'M' and 'MB' are both 1024².
     *
     * @throws InvalidArgumentException for anything else
     */
    public static function bytes(int|string $size): int
    {
        if (is_int($size)) {
            if ($size < 1) {
                throw new InvalidArgumentException('An upload size must be at least 1 byte.');
            }

            return $size;
        }

        if (! preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/Di', $size, $m) || (int) $m[1] < 1) {
            throw new InvalidArgumentException("[{$size}] is not an upload size. Use bytes, or a number with K, M or G.");
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }
}
