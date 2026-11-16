<?php

declare(strict_types=1);

namespace BarkaPay\Http;

/**
 * An outbound HTTP request. Plain value object so a custom
 * {@see HttpClientInterface} can be plugged in without depending on PSR types.
 *
 * ⚠️ `$headers` holds the real `Authorization` header — the transport needs
 * it. `var_dump()`, `print_r()` and `json_encode()` of this object mask it,
 * but `var_export()`, an `(array)` cast, `serialize()` and dumpers built on
 * them (`dd()`) do not: log {@see redactedHeaders()}, never the object.
 * A transport should also mark its `Request` parameter `#[\SensitiveParameter]`
 * so it stays out of stack traces.
 */
final class Request implements \JsonSerializable
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
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
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
