<?php

declare(strict_types=1);

namespace PayHub\Http;

/**
 * An outbound HTTP request. Plain value object so a custom
 * {@see HttpClientInterface} can be plugged in without depending on PSR types.
 *
 * The `Authorization` header carries the API key: never log `$headers` as-is.
 * `var_dump()` / `print_r()` on this object are safe — credentials are masked.
 */
final class Request
{
    /** Header names whose value must never reach a log or a dump. */
    private const SENSITIVE_HEADERS = ['authorization', 'x-api-key', 'proxy-authorization', 'cookie'];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        #[\SensitiveParameter]
        public readonly array $headers = [],
        public readonly ?string $body = null,
    ) {
    }

    /**
     * Headers with credentials masked — the form to use in logs.
     *
     * @return array<string, string>
     */
    public function redactedHeaders(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[$name] = \in_array(strtolower($name), self::SENSITIVE_HEADERS, true) ? '[REDACTED]' : $value;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $this->redactedHeaders(),
            'body' => $this->body === null ? null : '[' . \strlen($this->body) . ' bytes]',
        ];
    }
}
