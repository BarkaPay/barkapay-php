<?php

declare(strict_types=1);

namespace PayHub\Http;

use PayHub\Exception\NetworkException;

/**
 * Minimal transport contract. The SDK ships a zero-dependency curl
 * implementation ({@see CurlClient}); pass your own to integrate an existing
 * HTTP stack (e.g. a PSR-18 adapter) or to fake the network in tests.
 *
 * Implementations MUST:
 *  - verify the server's TLS certificate and host name;
 *  - NOT follow redirects (the request carries the API key in `Authorization`);
 *  - keep that header out of logs and traces: log `Request::redactedHeaders()`
 *    and mark the `$request` parameter `#[\SensitiveParameter]`;
 *  - return every HTTP response as a {@see Response}, whatever its status;
 *  - throw {@see NetworkException} when no response was received, flagging
 *    `connectionFailure: true` only when the request provably never left
 *    (DNS failure, refused connection). The flag is information for the
 *    caller: the SDK itself never replays a write on a network error.
 */
interface HttpClientInterface
{
    /**
     * @throws NetworkException when the request never reaches a response
     */
    public function send(Request $request): Response;
}
