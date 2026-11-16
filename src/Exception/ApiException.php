<?php

declare(strict_types=1);

namespace PayHub\Exception;

use PayHub\DTO\Payment;
use PayHub\DTO\Transfer;
use PayHub\Util\Headers;

/**
 * Base class for every error returned by the API (any response with the
 * standard error envelope or a non-2xx status). Subclasses map the most common
 * cases so you can catch them precisely.
 *
 * What you can read on every instance:
 *  - {@see getErrorCode()}  — machine-readable envelope code (`validation_error`, `payment_failed`, …);
 *  - {@see getHttpStatus()} — HTTP status;
 *  - {@see getRequestId()}  — correlation id to quote to support;
 *  - {@see getErrors()}     — field → messages map (validation errors);
 *  - {@see getData()}       — the envelope `data` (failed payment / transfer snapshot);
 *  - {@see getRetryAfter()} — seconds to wait, when the API sent `Retry-After`.
 */
class ApiException extends \RuntimeException implements PayHubException
{
    /** Longest raw body kept on the exception (an HTML error page can be huge). */
    private const MAX_BODY_LENGTH = 16384;

    /**
     * @param array<string, mixed>  $errors          field-level details (validation) or context
     * @param array<string, mixed>  $data            envelope `data` (e.g. the failed payment snapshot)
     * @param array<string, string> $responseHeaders response headers, lower-cased names
     * @param string|null           $responseBody    raw response body
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = '',
        private readonly int $httpStatus = 0,
        private readonly ?string $requestId = null,
        private readonly array $errors = [],
        private readonly array $data = [],
        private readonly array $responseHeaders = [],
        private readonly ?string $responseBody = null,
    ) {
        parent::__construct($message);
    }

    /** Machine-readable code from the envelope (e.g. `validation_error`). Empty when the response had no envelope. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Correlation id echoed by the API — quote it to support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Field → messages map of a validation error (`['amount' => ['…']]`).
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The envelope `data`, when the error carries one. A failed payment or
     * transfer comes back here as `['payment' => […]]` / `['transfer' => […]]`
     * — see {@see getPayment()} and {@see getTransfer()}.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * The payment the API recorded for this failed attempt (status `FAILED`,
     * with `reasonCode` / `providerReason`), when the error carries one.
     * Persist its `publicId`: the attempt exists on the API side.
     */
    public function getPayment(): ?Payment
    {
        $payment = $this->data['payment'] ?? null;
        if (!\is_array($payment)) {
            return null;
        }

        /** @var array<string, mixed> $payment */
        return Payment::fromArray($payment);
    }

    /** The transfer the API recorded for this failed attempt, when the error carries one. */
    public function getTransfer(): ?Transfer
    {
        $transfer = $this->data['transfer'] ?? null;
        if (!\is_array($transfer)) {
            return null;
        }

        /** @var array<string, mixed> $transfer */
        return Transfer::fromArray($transfer);
    }

    /**
     * Seconds the API asked you to wait before retrying (`Retry-After`
     * header), or null when it did not say. Sent with 429 and some 503.
     */
    public function getRetryAfter(): ?int
    {
        return Headers::retryAfter($this->responseHeaders);
    }

    /**
     * Response headers, lower-cased names (`x-ratelimit-remaining`, `retry-after`, …).
     *
     * @return array<string, string>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /** Case-insensitive lookup of one response header. */
    public function getResponseHeader(string $name): ?string
    {
        return $this->responseHeaders[strtolower($name)] ?? null;
    }

    /** Raw response body (truncated to 16 KiB) — useful when the response was not the JSON envelope. */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * Build the most specific exception for an error response.
     *
     * @param array<string, mixed>                $envelope        the decoded body (empty when it was not a JSON object)
     * @param array<string, string|list<string>> $responseHeaders
     */
    public static function fromEnvelope(
        int $httpStatus,
        array $envelope,
        array $responseHeaders = [],
        ?string $responseBody = null,
    ): self {
        $code = \is_string($envelope['code'] ?? null) ? $envelope['code'] : '';
        $rawMessage = $envelope['message'] ?? null;
        $message = \is_string($rawMessage) && $rawMessage !== ''
            ? $rawMessage
            : self::defaultMessage($httpStatus);
        $requestId = \is_string($envelope['request_id'] ?? null) ? $envelope['request_id'] : null;
        /** @var array<string, mixed> $errors */
        $errors = \is_array($envelope['errors'] ?? null) ? $envelope['errors'] : [];
        /** @var array<string, mixed> $data */
        $data = \is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $headers = Headers::normalize($responseHeaders);
        $requestId ??= $headers['x-request-id'] ?? null;

        if ($responseBody !== null && \strlen($responseBody) > self::MAX_BODY_LENGTH) {
            $responseBody = substr($responseBody, 0, self::MAX_BODY_LENGTH);
        }

        $class = self::classFor($code, $httpStatus);

        return new $class($message, $code, $httpStatus, $requestId, $errors, $data, $headers, $responseBody);
    }

    private static function defaultMessage(int $httpStatus): string
    {
        if ($httpStatus >= 300 && $httpStatus < 400) {
            return 'Unexpected redirect from the barkapay API (HTTP ' . $httpStatus . '). '
                . 'Redirects are never followed — check the base URL.';
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return 'Unexpected response from the barkapay API (HTTP ' . $httpStatus . '): not a JSON envelope.';
        }

        return 'barkapay API error (HTTP ' . $httpStatus . ').';
    }

    /** @return class-string<self> */
    private static function classFor(string $code, int $httpStatus): string
    {
        return match ($code) {
            'unauthenticated', 'invalid_api_key', 'incorrect_credentials' => AuthenticationException::class,
            'unauthorized', 'ip_not_allowed', 'merchant_suspended', 'merchant_closed' => AuthorizationException::class,
            'validation_error', 'invalid_input' => ValidationException::class,
            'resource_not_found' => NotFoundException::class,
            'duplicate_transaction', 'resource_already_exists' => ConflictException::class,
            'too_many_requests' => RateLimitException::class,
            'service_unavailable', 'operator_unavailable' => ServiceUnavailableException::class,
            'insufficient_balance' => InsufficientBalanceException::class,
            'payment_failed' => PaymentFailedException::class,
            'transfer_failed' => TransferFailedException::class,
            default => match (true) {
                401 === $httpStatus => AuthenticationException::class,
                403 === $httpStatus => AuthorizationException::class,
                404 === $httpStatus => NotFoundException::class,
                409 === $httpStatus => ConflictException::class,
                422 === $httpStatus => ValidationException::class,
                429 === $httpStatus => RateLimitException::class,
                503 === $httpStatus => ServiceUnavailableException::class,
                // A 405 comes back with the envelope code `internal_error`:
                // the status decides, so it is never mistaken for a 5xx.
                $httpStatus >= 500 => ServerException::class,
                default => self::class,
            },
        };
    }
}
