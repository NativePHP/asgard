<?php

namespace Native\Asgard\Http;

use Native\Asgard\Security\SessionKeys;

/**
 * A request that passed the checks in §7.6, as the controllers see it: the
 * session, its keys, the request's own signature (responses and stream
 * events are bound to it) and the decrypted body.
 */
final class SecureRequest
{
    public const ATTRIBUTE = 'asgard.secure';

    public function __construct(
        public readonly string $session,
        public readonly SessionKeys $keys,
        public readonly string $signature,
        public readonly string $body,
        public readonly ?string $nonce,
    ) {}
}
