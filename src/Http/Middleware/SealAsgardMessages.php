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
 * Checks every render, call, routes and stream request before anything
 * reads its body, and seals every answer (protocol §7.6).
 *
 * The order is the protocol's: method, session known (419), Content-Digest,
 * signature, decrypt (403), then the app's own middleware such as bearer
 * auth, then the controller. Rejections are plain JSON, because there is no
 * session to sign them with or no reason to trust the one claimed. Anything
 * the controller or the app's middleware answers is encrypted with enc_s and
 * signed with sig_s, bound to the request. A stream seals its own events.
 */
class SealAsgardMessages
{
    public function __construct(protected SessionStore $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getRealMethod() === 'POST'
            && strtoupper((string) $request->headers->get('X-Asgard-Method')) !== 'QUERY') {
            return $this->plain(405, 'method_not_allowed', 'POST requires the X-Asgard-Method: QUERY header.')
                ->header('Allow', 'QUERY, POST');
        }

        $session = (string) $request->headers->get('Asgard-Session-Id', '');
        $keys = $session === '' ? null : $this->sessions->find($session);

        if ($keys === null) {
            return $this->plain(419, 'session_expired', 'Unknown or expired Asgard session. Handshake again.');
        }

        $now = now()->getTimestamp();

        try {
            $opened = Wire::openRequest(
                $keys,
                $session,
                $request->getRealMethod(),
                $request->getHttpHost(),
                $this->path($request),
                $this->headers($request),
                $request->getContent(),
                $now,
            );
        } catch (SecurityException $e) {
            return $this->reject($e->getMessage());
        }

        if ($opened['nonce'] !== null && ! $this->sessions->claimNonce($session, $opened['nonce'], $opened['expires'], $now)) {
            return $this->reject('The nonce was already used.');
        }

        $this->sessions->touch($session);

        $request->attributes->set(SecureRequest::ATTRIBUTE, new SecureRequest(
            $session, $keys, $opened['signature'], $opened['body'], $opened['nonce'],
        ));

        $response = $next($request);

        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Only a fragment from a region that allows it is compressed. Errors
        // never are: they are small and may echo what the client sent.
        $sealed = Wire::sealResponse(
            $keys, $session, $response->getStatusCode(), (string) $response->getContent(), $opened['signature'],
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
