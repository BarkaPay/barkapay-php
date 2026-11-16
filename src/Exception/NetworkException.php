<?php

declare(strict_types=1);

namespace PayHub\Exception;

/**
 * The HTTP request never completed (DNS, connection, TLS, timeout): there is
 * no response to read.
 *
 * ⚠️ For a request that moves money (`payments->create()`,
 * `transfers->create()`, `payments->confirmOtp()`), a timeout does NOT mean
 * nothing happened — the API may have processed it. Look the operation up by
 * your `order.id` before trying again, unless {@see isConnectionFailure()} is
 * true.
 */
final class NetworkException extends \RuntimeException implements PayHubException
{
    private ?string $requestId = null;

    /**
     * @param bool $connectionFailure true only when the request provably never
     *                                reached the API (DNS failure, refused
     *                                connection, failed TLS handshake)
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $connectionFailure = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * True when the connection was never established, so the API received
     * nothing: repeating the request cannot duplicate anything.
     */
    public function isConnectionFailure(): bool
    {
        return $this->connectionFailure;
    }

    /**
     * The `X-Request-Id` the SDK sent with the request — quote it to support
     * to find out whether the request reached the API.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** @internal */
    public function withRequestId(string $requestId): self
    {
        $this->requestId ??= $requestId;

        return $this;
    }
}
