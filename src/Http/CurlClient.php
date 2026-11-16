<?php

declare(strict_types=1);

namespace BarkaPay\Http;

use BarkaPay\Exception\ConfigurationException;
use BarkaPay\Exception\NetworkException;

/**
 * Zero-dependency HTTP client built on ext-curl. No Guzzle, no PSR packages —
 * safe to embed inside WordPress / WooCommerce where dependency versions clash.
 *
 * Security posture (not configurable, on purpose):
 *  - the server certificate and host name are always verified;
 *  - redirects are never followed (the `Authorization` header must not travel
 *    to another URL);
 *  - only `http`/`https` are spoken, whatever the URL says.
 *
 * If your PHP build has no usable CA bundle (some Windows stacks), point
 * `$caBundle` at a `cacert.pem` file rather than looking for a way to turn
 * verification off — there is none.
 */
final class CurlClient implements HttpClientInterface
{
    /**
     * The hub gives a gateway 30 s and then answers 503 itself: waiting a
     * little longer lets the SDK receive that verdict instead of giving up on
     * an unknown outcome.
     */
    public const DEFAULT_TIMEOUT = 40.0;
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    /**
     * Hard cap on a response body, in bytes. API responses are a few
     * kilobytes; anything larger is not the API, and is refused before it can
     * exhaust the memory of the integration.
     */
    public const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    /**
     * curl errors raised before any byte of the request was sent: resolving
     * the proxy / host, opening the TCP connection.
     */
    private const CONNECTION_ERRORS = [
        5, // CURLE_COULDNT_RESOLVE_PROXY
        6, // CURLE_COULDNT_RESOLVE_HOST
        7, // CURLE_COULDNT_CONNECT
    ];

    private readonly float $timeout;
    private readonly float $connectTimeout;

    /**
     * @param float       $timeout        total time allowed for one request, in seconds
     * @param float       $connectTimeout time allowed to establish the connection, in seconds
     * @param string|null $caBundle       path to a PEM CA bundle; null uses the system store
     *
     * @throws ConfigurationException when `$caBundle` is not a readable file
     */
    public function __construct(
        float $timeout = self::DEFAULT_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private readonly ?string $caBundle = null,
    ) {
        $this->timeout = self::seconds($timeout, self::DEFAULT_TIMEOUT);
        $this->connectTimeout = min(self::seconds($connectTimeout, self::DEFAULT_CONNECT_TIMEOUT), $this->timeout);

        if ($caBundle !== null && !is_file($caBundle)) {
            throw new ConfigurationException('The CA bundle file does not exist or is not readable.');
        }
    }

    public function send(
        #[\SensitiveParameter]
        Request $request,
    ): Response {
        if (!\function_exists('curl_init') || !\function_exists('curl_exec')) {
            throw new NetworkException(
                'The curl functions are not available on this host (extension missing or disabled).',
                0,
                null,
                true,
            );
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new NetworkException('Unable to initialise curl.', 0, null, true);
        }

        /** @var array<string, string> $headers */
        $headers = [];

        $options = $this->options($request);

        // The body is collected here rather than by CURLOPT_RETURNTRANSFER so
        // that an oversized one is cut off while it streams in, whether or
        // not it announced a Content-Length.
        $body = '';
        $tooLarge = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
            if (\strlen($body) + \strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                $tooLarge = true;

                return 0; // aborts the transfer (CURLE_WRITE_ERROR)
            }
            $body .= $chunk;

            return \strlen($chunk);
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (&$headers): int {
            $trimmed = trim($line);
            if (stripos($trimmed, 'HTTP/') === 0) {
                // A new status line (100-continue, proxy CONNECT…): only the
                // final response's headers are kept.
                $headers = [];
            } elseif ($trimmed !== '') {
                $parts = explode(':', $trimmed, 2);
                if (\count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }

            return \strlen($line);
        };

        // curl_setopt_array() stops at the first option it cannot set: never
        // send a request with only part of the configuration applied.
        try {
            $configured = curl_setopt_array($handle, $options);
            $reason = $configured ? '' : curl_error($handle);
        } catch (\Throwable $e) {
            // Deliberately not chained: the trace of an error raised inside
            // curl_setopt_array() holds the options, Authorization header included.
            $configured = false;
            $reason = $e::class;
        }

        if (!$configured) {
            throw new NetworkException(rtrim('Unable to configure curl: ' . $reason, ': '), 0, null, true);
        }

        $result = curl_exec($handle);

        if ($result === false || $tooLarge) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);

            if ($tooLarge || $errno === 63) { // 63 = CURLE_FILESIZE_EXCEEDED
                throw new NetworkException('The response body exceeds the maximum accepted size.', $errno);
            }

            throw new NetworkException(
                $error !== '' ? $error : 'HTTP request failed.',
                $errno,
                null,
                \in_array($errno, self::CONNECTION_ERRORS, true),
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // No curl_close(): the handle is an object, released when it goes out
        // of scope (the function is a no-op since PHP 8.0, deprecated in 8.5).
        return new Response($status, $body, $headers);
    }

    /**
     * A timeout curl can honour: finite, at least 1 ms, at most 10 minutes.
     * Anything else falls back to the default — in particular 0, which curl
     * reads as "wait forever", and values that overflow once in milliseconds.
     */
    private static function seconds(float $value, float $default): float
    {
        if (!is_finite($value) || $value <= 0) {
            return $default;
        }

        return max(0.001, min($value, 600.0));
    }

    /**
     * The curl options for a request (header callback aside). Exposed so the
     * security-critical ones can be asserted in tests.
     *
     * @internal
     *
     * @return array<int, mixed>
     */
    public function options(
        #[\SensitiveParameter]
        Request $request,
    ): array {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        // Never let curl add `Expect: 100-continue` on larger bodies.
        $headers[] = 'Expect:';

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            // Refused up front when the size is announced; see send() for the rest.
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,

            // TLS: always verify the chain and the host name.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // Never follow a redirect with the Authorization header attached.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,

            // http(s) only — no file://, gopher://, … whatever the URL says.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,

            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeout * 1000),
            // Required for sub-second timeouts to be honoured on every build.
            CURLOPT_NOSIGNAL => true,

            // Accept every compression curl was built with.
            CURLOPT_ENCODING => '',
        ];

        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif (\in_array(strtoupper($request->method), ['POST', 'PUT', 'PATCH'], true)) {
            // Body-less write: still announce `Content-Length: 0`.
            $options[CURLOPT_POSTFIELDS] = '';
        }

        return $options;
    }
}
