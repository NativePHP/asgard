<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Native\Asgard\Http\SecureRequest;
use Native\Asgard\Http\SessionController;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\SessionStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Checks every render, call, upload, routes and stream request before
 * anything reads its body, and seals every answer (Asgard protocol §7.6).
 *
 * RequireProtocolAndCoding has already done checks 1 and 2. This does the
 * rest of the first seven in the protocol's order: session known (419) and
 * made with the request's Asgard-Protocol (422), Content-Digest and
 * signature (403), nonce (403), decrypt (403). Those rejections are plain
 * JSON, because there is no session to sign them with or no reason to
 * trust the one claimed.
 *
 * `$kind` says what the endpoint is. `unsafe` (call, upload) refuses a
 * signature without a nonce with 403 nonce_required before decrypting
 * (§7.3). `read` (routes, stream) and `render` are check 8: once the body
 * is open, a read whose signature carries a nonce gets a sealed 422
 * invalid_request, and a render with an `action` and no nonce a sealed
 * 403 nonce_required. Only then does the app's own middleware, such as
 * bearer auth, see the request.
 *
 * Anything the controller or the app's middleware answers is encrypted
 * with enc_s and signed with sig_s, bound to the request. A stream seals
 * its own events. RequireQuery has already refused a POST without
 * X-Asgard-Method.
 */
class SealAsgardMessages
{
    public function __construct(protected SessionStore $sessions) {}

    public function handle(Request $request, Closure $next, string $kind = 'render'): Response
    {
        $session = (string) $request->headers->get('Asgard-Session-Id', '');
        $opened = $session === '' ? null : $this->sessions->open($session);

        if ($opened === null) {
            return $this->plain(419, 'session_expired', 'Unknown or expired Asgard session. Handshake again.');
        }

        if (RequireProtocolAndCoding::version($request) !== $opened['protocol']) {
            return response()->json([
                'error' => 'unsupported_protocol',
                'protocols' => SessionController::PROTOCOLS,
                'message' => 'Asgard-Protocol is not the version this session was made with.',
            ], 422);
        }

        $keys = $opened['keys'];
        $now = now()->getTimestamp();
        $headers = $this->headers($request);

        try {
            $verified = Wire::verifyRequest(
                $keys,
                $session,
                $request->getRealMethod(),
                $request->getHttpHost(),
                $this->path($request),
                $headers,
                $request->getContent(),
                $now,
            );
        } catch (SecurityException $e) {
            return $this->reject($e->getMessage());
        }

        if ($verified['nonce'] === null && $kind === 'unsafe') {
            return $this->plain(403, 'nonce_required', 'This endpoint changes state, so its signature needs a nonce.');
        }

        if ($verified['nonce'] !== null && ! $this->sessions->claimNonce($session, $verified['nonce'], $verified['expires'], $now)) {
            return $this->reject('The nonce was already used.');
        }

        try {
            $body = Wire::decryptRequest($keys, $session, $headers, $request->getContent());
        } catch (SecurityException $e) {
            return $this->reject($e->getMessage());
        }

        $this->sessions->touch($session);

        $request->attributes->set(SecureRequest::ATTRIBUTE, new SecureRequest(
            $session, $keys, $verified['signature'], $body, $verified['nonce'], $opened['elements'],
        ));

        $response = $this->nonceProblem($kind, $body, $verified['nonce'] !== null) ?? $next($request);

        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Only a fragment from a region that allows it is compressed. Errors
        // never are: they are small and may echo what the client sent.
        // Asgard-Cache tells the client to replace its cached regions, so
        // the signature covers it whenever it is there.
        $cache = $response->headers->get('Asgard-Cache');

        $sealed = Wire::sealResponse(
            $keys, $session, $response->getStatusCode(), (string) $response->getContent(), $verified['signature'],
            now()->getTimestamp(), null, $request->attributes->get(SecureRequest::COMPRESS) === true,
            $cache === null || $cache === '' ? null : $cache,
        );

        $response->setContent($sealed['body']);
        foreach ($sealed['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Check 8 of §7.6, once the body is open: an action needs a nonce and a
     * read must not carry one. A render is an action when its body has an
     * `action` member. A body that is not a JSON object is left for the
     * controller to refuse.
     */
    protected function nonceProblem(string $kind, string $body, bool $hasNonce): ?JsonResponse
    {
        $action = false;

        if ($kind === 'render') {
            $decoded = json_decode($body, true, 64);
            $action = is_array($decoded) && array_key_exists('action', $decoded);
        }

        if ($action && ! $hasNonce) {
            return response()->json(['error' => 'nonce_required'], 403);
        }

        if ($hasNonce && ! $action && $kind !== 'unsafe') {
            return response()->json(['error' => 'invalid_request', 'message' => 'A read must not carry a nonce.'], 422);
        }

        return null;
    }

    /** The path as it was on the wire, without the query. */
    protected function path(Request $request): string
    {
        return explode('?', $request->getRequestUri(), 2)[0];
    }

    /** @return array<string, string> */
    protected function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', array_map('trim', $values));
        }

        return $headers;
    }

    protected function reject(string $reason): JsonResponse
    {
        Log::notice('Asgard: rejected a request. '.$reason);

        return $this->plain(403, 'forbidden', 'The request failed its integrity check.');
    }

    protected function plain(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
