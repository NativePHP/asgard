<?php

namespace Native\Asgard\Http;

use finfo;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Native\Asgard\Attributes\Upload;
use Native\Asgard\Blade\RegionViews;
use Native\Asgard\ComponentRegistry;
use Native\Asgard\Exceptions\InvalidRequest;
use Native\Asgard\Exceptions\TooManyRegions;
use Native\Asgard\Http\Concerns\AnswersWithDelta;
use Native\Asgard\Http\Concerns\ReadsAsgardRequests;
use Native\Asgard\Http\Concerns\RendersRegions;
use Native\Asgard\Support\ActionInvoker;
use Native\Asgard\Support\Callback;
use Native\Asgard\Support\ComponentMetadata;
use Native\Asgard\Support\Hydrator;
use Native\Asgard\Support\PushVersions;
use RuntimeException;

/**
 * Takes a file from the device and hands it to an `#[Upload]` method
 * (Asgard protocol §10.6).
 *
 * The decrypted body is a frame, not JSON:
 *
 *     uint32, big-endian   N, the length of the header
 *     N bytes              the header, a JSON object
 *     the rest             the file's bytes, exactly `size` of them
 *
 *     header: {"protocol":1,"component":"app.native.screens.home",
 *              "method":"savePhoto","args":[],"state":{…},
 *              "filename":"IMG_0001.jpg","mime":"image/jpeg","size":123456}
 *
 * The bytes go to a temp file whose name the server picks. The device's
 * filename and mime type are never trusted: the type is sniffed from the
 * bytes and checked against the attribute's `mimes`, and the filename is
 * only kept, cleaned, as getClientOriginalName() for display. The method
 * gets an UploadedFile as its first argument, then the args. The header may
 * carry a call's `read_only` and `regions` too. The answer is a call's: the
 * delta, plus the regions it named and any regions and hints refresh()
 * asked for. The
 * temp file is deleted afterwards, so a method that wants to keep the file
 * must store it.
 *
 * Errors: 404 unknown_component or unknown_method, 413 too_large, 415
 * unsupported_type, 422 invalid_request. An upload changes state, like a
 * call, so SealAsgardMessages has already refused one without a nonce, with
 * a plain 403 nonce_required, before it decrypted anything.
 */
class UploadController
{
    use AnswersWithDelta;
    use ReadsAsgardRequests;
    use RendersRegions;

    /** The largest header a frame may carry. State rides in it. */
    public const HEADER_MAX = 1024 * 1024;

    /** Any other member is refused (§5.3). */
    private const FIELDS = ['protocol', 'component', 'method', 'args', 'state', 'read_only', 'regions', 'cache', 'filename', 'mime', 'size'];

    public function __construct(
        protected ComponentRegistry $registry,
        protected Container $container,
        protected RegionViews $views,
        protected PushVersions $versions,
    ) {}

    /** The cap from `asgard.uploads.max_size`, in bytes. */
    public static function maxSize(): int
    {
        return Upload::bytes(config('asgard.uploads.max_size', '20M') ?? '20M');
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            [$payload, $offset] = $this->frame($request);
        } catch (TooManyRegions $e) {
            return $this->error(422, 'too_many_regions', $e->getMessage());
        } catch (InvalidRequest $e) {
            return $this->error(422, 'invalid_request', $e->getMessage());
        }

        $class = $this->registry->resolve($payload['component']);

        if ($class === null) {
            return response()->json(['error' => 'unknown_component'], 404);
        }

        $metadata = ComponentMetadata::for($class);
        $upload = $metadata->uploads[$payload['method']] ?? null;

        if ($upload === null || ! ActionInvoker::accepts($upload['spec'], count($payload['args']))) {
            return response()->json(['error' => 'unknown_method'], 404);
        }

        if ($unknown = $this->unknownRegion($metadata, $payload['regions'])) {
            return $unknown;
        }

        $max = min(self::maxSize(), $upload['maxSize'] ?? PHP_INT_MAX);

        if ($payload['size'] > $max) {
            return $this->error(413, 'too_large', "The file is {$payload['size']} bytes and this method takes at most {$max}.");
        }

        $path = $this->store($this->secure($request)->body, $offset);

        try {
            $mime = $this->sniff($path);

            if (! $this->allowed($mime, $upload['mimes'])) {
                return $this->error(415, 'unsupported_type', "The file is {$mime}, which this method does not take.");
            }

            $file = new UploadedFile($path, $payload['filename'], $mime, UPLOAD_ERR_OK, true);

            $component = $this->container->make($class);
            $component->setAsgardContext('');
            $this->giveClientCache($component, $metadata, $payload['cache']);

            try {
                Hydrator::hydrate($component, $payload['state']);
                $before = $this->fingerprints($component);

                $channels = $this->channelsOf($component);
                $tokens = $payload['regions'] === [] ? [] : $this->tokens($payload['component'], $payload['regions'], $channels);

                ActionInvoker::invoke($component, $payload['method'], $upload['spec'], $payload['args'], 'args', [$file]);
            } catch (InvalidRequest $e) {
                return $this->error(422, 'invalid_request', $e->getMessage());
            }

            return $this->answer($component, $metadata, $payload['component'], $payload['method'], $channels, $before, $payload['state'], $payload['read_only'], $payload['regions'], $tokens);
        } finally {
            // The method may have moved it already.
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The header, checked, and where the file's bytes start in the body.
     *
     * @return array{0: array{component: string, method: string, args: list<mixed>, state: array<string, mixed>, read_only: list<string>, regions: list<string>, cache: array<string, array{made_at: int, ttl: int}>, filename: string, size: int}, 1: int}
     */
    protected function frame(Request $request): array
    {
        $header = $request->headers->get('Asgard-Protocol');
        if ($header !== null && $header !== (string) self::PROTOCOL) {
            throw new InvalidRequest('Unsupported Asgard-Protocol header.');
        }

        $body = $this->secure($request)->body;

        if (strlen($body) < 4) {
            throw new InvalidRequest('Body must be an upload frame.');
        }

        $length = unpack('N', $body)[1];

        if ($length < 2 || $length > self::HEADER_MAX || 4 + $length > strlen($body)) {
            throw new InvalidRequest('The frame header has a bad length.');
        }

        try {
            $head = json_decode(substr($body, 4, $length), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRequest('The frame header must be valid JSON.');
        }

        if (! is_array($head) || array_is_list($head) && $head !== []) {
            throw new InvalidRequest('The frame header must be a JSON object.');
        }

        if (array_diff(array_map('strval', array_keys($head)), self::FIELDS) !== []) {
            throw new InvalidRequest('The frame header has unknown fields.');
        }

        if (($head['protocol'] ?? null) !== self::PROTOCOL) {
            throw new InvalidRequest('protocol must be 1.');
        }

        if (! array_key_exists('method', $head) || ! array_key_exists('args', $head)) {
            throw new InvalidRequest('An upload needs both method and args.');
        }

        if ($problem = Callback::nameProblem($head['method']) ?? Callback::argsProblem($head['args'])) {
            throw new InvalidRequest(str_replace("'call'", "'method'", $problem));
        }

        $size = $head['size'] ?? null;
        $offset = 4 + $length;

        if (! is_int($size) || $size !== strlen($body) - $offset) {
            throw new InvalidRequest('size must be the number of bytes after the header.');
        }

        if ($size === 0) {
            throw new InvalidRequest('The file is empty.');
        }

        if (array_key_exists('mime', $head) && $head['mime'] !== null && (! is_string($head['mime']) || strlen($head['mime']) > 255)) {
            throw new InvalidRequest('mime must be a string.');
        }

        $regions = $this->regionList($head['regions'] ?? [], true);
        $state = $this->state($head['state'] ?? null);

        return [[
            'component' => $this->component($head['component'] ?? null),
            'method' => $head['method'],
            'args' => $head['args'],
            'state' => $state,
            'read_only' => $this->readOnly($head['read_only'] ?? null, $state),
            'regions' => $regions,
            'cache' => $this->cache($head['cache'] ?? null),
            'filename' => $this->filename($head['filename'] ?? null),
            'size' => $size,
        ], $offset];
    }

    /**
     * The device's filename, for display only. Never part of a path: the
     * last segment, without control characters, at most 255 bytes.
     */
    protected function filename(mixed $name): string
    {
        if ($name !== null && ! is_string($name)) {
            throw new InvalidRequest('filename must be a string.');
        }

        $name = (string) $name;

        if (! mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidRequest('filename must be UTF-8.');
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        $name = mb_strcut($name, 0, 255, 'UTF-8');

        return $name === '' || $name === '.' || $name === '..' ? 'upload' : $name;
    }

    /** Write the bytes after the header to a temp file the server names. */
    protected function store(string $body, int $offset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asgard-upload-');

        if ($path === false || file_put_contents($path, substr($body, $offset)) === false) {
            throw new RuntimeException('Asgard could not write an upload to the temp directory.');
        }

        return $path;
    }

    /** The type the bytes really have. */
    protected function sniff(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /** @param  list<string>  $mimes */
    protected function allowed(string $mime, array $mimes): bool
    {
        if ($mimes === []) {
            return true;
        }

        foreach ($mimes as $allowed) {
            if ($allowed === $mime || (str_ends_with($allowed, '/*') && str_starts_with($mime, substr($allowed, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
