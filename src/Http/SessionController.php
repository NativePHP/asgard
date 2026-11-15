<?php

namespace Native\Asgard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Support\FragmentValidator;
use Native\Asgard\Support\SessionStore;

/**
 * The handshake (Asgard protocol §6.1). The client sends its ephemeral P-256
 * public key, the server answers with its own, and each side derives the
 * session secret from the ECDH result. The secret never crosses the wire.
 */
class SessionController
{
    public const PROTOCOL = 1;

    public const ENCODING = 'tree/json';

    /** At most this many extra element types in `elements`. */
    public const MAX_ELEMENTS = 256;

    /** The longest element type name `elements` may hold. */
    public const MAX_ELEMENT_LENGTH = 64;

    public function __construct(protected SessionStore $sessions) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(422, 'invalid_request', 'Body must be valid JSON.');
        }

        if (! is_array($body)) {
            return $this->error(422, 'invalid_request', 'Body must be a JSON object.');
        }

        $key = (string) config('asgard.key', '');
        if ($key === '' || ! is_string($body['key'] ?? null) || ! hash_equals($key, $body['key'])) {
            return $this->error(403, 'unknown_key', 'This server does not know that Asgard key.');
        }

        $offered = $body['protocols'] ?? null;
        if (! is_array($offered) || ! array_is_list($offered) || $offered === [] || count($offered) > 16
            || count(array_unique($offered, SORT_REGULAR)) !== count($offered)
            || array_filter($offered, fn ($v) => ! is_int($v) || $v < 1) !== []) {
            return $this->error(422, 'invalid_request', 'protocols must be a list of 1 to 16 distinct positive integers.');
        }

        if (! in_array(self::PROTOCOL, $offered, true)) {
            return response()->json(['error' => 'unsupported_protocol', 'protocols' => [self::PROTOCOL]], 422);
        }

        $accepts = $body['accepts'] ?? null;
        if (! is_array($accepts) || ! in_array(self::ENCODING, $accepts, true)) {
            return response()->json(['error' => 'unsupported_encoding', 'encodings' => [self::ENCODING]], 422);
        }

        $elements = $this->elements($body['elements'] ?? null);

        if ($elements === null) {
            return $this->error(422, 'invalid_request', 'elements must be a list of at most '.self::MAX_ELEMENTS.' distinct element type names.');
        }

        $clientPublic = is_string($body['client_public'] ?? null) ? Base64Url::decode($body['client_public']) : null;

        try {
            [$private, $serverPublic] = Ecdh::generate();
            $shared = Ecdh::agree($private, (string) $clientPublic);
        } catch (SecurityException) {
            return $this->error(422, 'invalid_request', 'client_public must be an uncompressed P-256 point in base64url.');
        }

        $session = $this->sessions->create(SessionKeys::secret($shared, $clientPublic, $serverPublic), $elements);

        return response()->json([
            'session' => $session,
            'server_public' => Base64Url::encode($serverPublic),
            'protocol' => self::PROTOCOL,
            'expires_in' => $this->sessions->lifetime(),
            'encoding' => self::ENCODING,
        ]);
    }

    /**
     * The extra element types the client can render (Asgard protocol §6.1):
     * a list of distinct snake_case type names, or absent for core types
     * only. Null when it is malformed.
     *
     * A chrome type or server_region is never allowed in a fragment, so one
     * the client lists is left out rather than kept (§9.3). Core types are
     * always allowed and need not be kept either.
     *
     * @return list<string>|null
     */
    protected function elements(mixed $elements): ?array
    {
        if ($elements === null) {
            return [];
        }

        if (! is_array($elements) || ! array_is_list($elements) || count($elements) > self::MAX_ELEMENTS) {
            return null;
        }

        foreach ($elements as $type) {
            if (! is_string($type) || strlen($type) > self::MAX_ELEMENT_LENGTH || ! preg_match('/^[a-z][a-z0-9_]*$/D', $type)) {
                return null;
            }
        }

        if (count(array_unique($elements)) !== count($elements)) {
            return null;
        }

        return array_values(array_diff($elements, FragmentValidator::CORE_TYPES, FragmentValidator::NEVER_TYPES));
    }

    protected function error(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
