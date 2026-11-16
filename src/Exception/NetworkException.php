<?php

declare(strict_types=1);

namespace BarkaPay\Exception;

/**
 * The HTTP request never completed (DNS, connection, TLS, timeout): there is
 * no response to read.
 *
 * The SDK retries reads on its own, never writes. For a request that moves
 * money (`payments->create()`, `transfers->create()`,
 * `payments->confirmOtp()`), a timeout does NOT mean nothing happened — the
 * API may have processed it. Look the operation up by your `order.id`
 * (`list(['order_id' => …])`) before sending it again.
 */
final class NetworkException extends \RuntimeException implements BarkaPayException
{
    private ?string $requestId = null;

    /**
     * @param bool $connectionFailure true only when the request provably never
     *                                reached the API (DNS failure, refused
     *                                connection)
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
     * True when the connection was never established (DNS failure, refused
     * connection): the API received nothing. Information for your own retry
     * decision — the SDK does not replay a write on it.
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
