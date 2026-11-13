<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Http\UploadController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an upload that is too big before anything hashes or decrypts it
 * (protocol §10.6). Runs ahead of SealAsgardMessages on the upload route.
 *
 * The limit is `asgard.uploads.max_size`, plus room for the frame header
 * and what encryption adds. A method's own #[Upload] maxSize is checked
 * later, once the body is open and the method is known.
 *
 * Like the other early rejections, the 413 is plain, unsigned JSON.
 */
class LimitUploadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = self::limit(UploadController::maxSize());
        $claimed = $request->headers->get('Content-Length');

        if ($claimed !== null && (int) $claimed > $limit) {
            return $this->tooLarge();
        }

        $length = strlen($request->getContent());

        if ($length > $limit) {
            return $this->tooLarge();
        }

        // PHP drops a body bigger than post_max_size and hands over nothing.
        if ($length === 0 && (int) $claimed > 0) {
            return response()->json([
                'error' => 'too_large',
                'message' => 'The body did not reach the app. Check post_max_size and the web server\'s body limit.',
            ], 413);
        }

        return $next($request);
    }

    /**
     * The largest sealed body a file of `$max` bytes can make: the frame,
     * plus the aes128gcm header (at most 276 bytes) and 17 bytes for each
     * 4079 byte record (RFC 8188, §7.4).
     */
    public static function limit(int $max): int
    {
        $plain = 4 + UploadController::HEADER_MAX + $max;

        return 276 + $plain + 17 * intdiv($plain + 4078, 4079);
    }

    protected function tooLarge(): JsonResponse
    {
        return response()->json(['error' => 'too_large', 'message' => 'The upload is larger than this server takes.'], 413);
    }
}
