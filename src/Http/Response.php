<?php

declare(strict_types=1);

namespace PayHub\Http;

use PayHub\Util\Headers;

/**
 * A raw HTTP response returned by an {@see HttpClientInterface}.
 */
final class Response
{
    /**
     * @param array<string, string|list<string>> $headers header name => value. A list of values per
     *                                                    header (PSR-7 style) is accepted too.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** Case-insensitive header lookup; null when the header is absent. Several values are joined with ", ". */
    public function header(string $name): ?string
    {
        return $this->normalizedHeaders()[strtolower($name)] ?? null;
    }

    /**
     * The headers with lower-cased names and exactly one string per header.
     *
     * @return array<string, string>
     */
    public function normalizedHeaders(): array
    {
        return Headers::normalize($this->headers);
    }
}
