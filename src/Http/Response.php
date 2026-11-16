<?php

declare(strict_types=1);

namespace PayHub\Http;

/**
 * A raw HTTP response returned by an {@see HttpClientInterface}.
 */
final class Response
{
    /**
     * @param array<string, string> $headers header name => value (looked up case-insensitively)
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** Case-insensitive header lookup; null when the header is absent. */
    public function header(string $name): ?string
    {
        $wanted = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $wanted) {
                return $value;
            }
        }

        return null;
    }
}
