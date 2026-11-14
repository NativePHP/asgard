<?php

namespace Native\Asgard\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Native\Asgard\Http\SecureRequest;
use Native\Asgard\Security\SecurityException;
use Native\Asgard\Security\Wire;
use Native\Asgard\Support\SessionStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Checks every render, call, upload, routes and stream request before
 * anything reads its body, and seals every answer (Asgard protocol §7.6).
 *
 * The order is the protocol's: session known (419), Content-Digest and
 * signature (403), nonce, decrypt (403), then the app's own middleware such
 * as bearer auth, then the controller. With the `nonce` parameter, as on
 * the call and upload routes, a signature without a nonce is refused with
 * 403 nonce_required before anything is decrypted (§7.3). A render action
 * is only known from its body, so render checks that itself, sealed.
 *
 * Rejections are plain JSON, because there is no session to sign them with
 * or no reason to trust the one claimed. Anything the controller or the
 * app's middleware answers is encrypted with enc_s and signed with sig_s,
 * bound to the request. A stream seals its own events. RequireQuery has
 * already refused a POST without X-Asgard-Method.
 */
class SealAsgardMessages
{
    public function __construct(protected SessionStore $sessions) {}

    public function handle(Request $request, Closure $next, ?string $nonce = null): Response
    {
        $session = (string) $request->headers->get('Asgard-Session-Id', '');
        $opened = $session === '' ? null : $this->sessions->open($session);

        if ($opened === null) {
            return $this->plain(419, 'session_expired', 'Unknown or expired Asgard session. Handshake again.');
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

        if ($verified['nonce'] === null && $nonce === 'nonce') {
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

        $response = $next($request);

        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Only a fragment from a region that allows it is compressed. Errors
        // never are: they are small and may echo what the client sent.
        $sealed = Wire::sealResponse(
            $keys, $session, $response->getStatusCode(), (string) $response->getContent(), $verified['signature'],
            now()->getTimestamp(), null, $request->attributes->get(SecureRequest::COMPRESS) === true,
        );

        $response->setContent($sealed['body']);
        foreach ($sealed['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
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
