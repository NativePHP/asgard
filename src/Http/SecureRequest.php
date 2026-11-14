<?php

namespace Native\Asgard\Http;

use Native\Asgard\Security\SessionKeys;

/**
 * A request that passed the checks in §7.6, as the controllers see it: the
 * session, its keys, the request's own signature (responses and stream
 * events are bound to it), the decrypted body, and the extra element types
 * the client advertised at the handshake.
 */
final class SecureRequest
{
    public const ATTRIBUTE = 'asgard.secure';

    /** Set to true by a controller whose answer may be gzipped before it is encrypted. */
    public const COMPRESS = 'asgard.compress';

    public function __construct(
        public readonly string $session,
        public readonly SessionKeys $keys,
        public readonly string $signature,
        public readonly string $body,
        public readonly ?string $nonce,
        /** @var list<string> */
        public readonly array $elements = [],
    ) {}
}
