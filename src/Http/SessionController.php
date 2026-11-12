<?php

namespace Native\Asgard\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Asgard\Security\Base64Url;
use Native\Asgard\Security\Ecdh;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\SessionKeys;
use Native\Asgard\Support\SessionStore;

/**
 * The handshake (protocol §6.1). The client sends its ephemeral P-256
 * public key, the server answers with its own, and each side derives the
 * session secret from the ECDH result. The secret never crosses the wire.
 */
class SessionController
{
    public const PROTOCOL = 1;

    public const ENCODING = 'tree/json';

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

        if (($body['protocol'] ?? null) !== self::PROTOCOL) {
            return response()->json(['error' => 'unsupported_protocol', 'protocols' => [self::PROTOCOL]], 422);
        }

        $accepts = $body['accepts'] ?? null;
        if (! is_array($accepts) || ! in_array(self::ENCODING, $accepts, true)) {
            return response()->json(['error' => 'unsupported_encoding', 'encodings' => [self::ENCODING]], 422);
        }

        $clientPublic = is_string($body['client_public'] ?? null) ? Base64Url::decode($body['client_public']) : null;

        try {
            [$private, $serverPublic] = Ecdh::generate();
            $shared = Ecdh::agree($private, (string) $clientPublic);
        } catch (SecurityException) {
            return $this->error(422, 'invalid_request', 'client_public must be an uncompressed P-256 point in base64url.');
        }

        $session = $this->sessions->create(SessionKeys::secret($shared, $clientPublic, $serverPublic));

        return response()->json([
            'session' => $session,
            'server_public' => Base64Url::encode($serverPublic),
            'expires_in' => $this->sessions->lifetime(),
            'encoding' => self::ENCODING,
        ]);
    }

    protected function error(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
