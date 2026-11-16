<?php

declare(strict_types=1);

namespace PayHub\Tests\Support;

use PayHub\Http\HttpClientInterface;
use PayHub\Http\Request;
use PayHub\Http\Response;

/**
 * Records the request and replays queued responses — no network.
 */
final class FakeHttpClient implements HttpClientInterface
{
    public ?Request $lastRequest = null;

    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue;

    /**
     * @param list<Response|\Throwable> $responses
     */
    public function __construct(array $responses)
    {
        $this->queue = $responses;
    }

    public function send(Request $request): Response
    {
        $this->lastRequest = $request;
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('FakeHttpClient: no more queued responses.');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }

    /**
     * A successful envelope around `$data`.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $meta
     */
    public static function ok(array $data = [], int $status = 200, ?array $meta = null): Response
    {
        $body = [
            'status' => 'success',
            'code' => $status === 201 ? 'resource_created' : 'success',
            'message' => 'OK',
            'timestamp' => '2026-11-16T12:00:00+00:00',
            'request_id' => '9b1c7e44-5f2a-4c8d-9a3e-1f6b0d2a7c45',
            'data' => $data,
        ];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return self::json($status, $body);
    }

    /**
     * An error envelope.
     *
     * @param array<string, mixed>  $extra   extra envelope keys (`errors`, `data`, …)
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message = 'Nope.', array $extra = [], array $headers = []): Response
    {
        return self::json($status, [
            'status' => $status >= 500 ? 'error' : 'failed',
            'code' => $code,
            'message' => $message,
            'timestamp' => '2026-11-16T12:00:00+00:00',
            'request_id' => 'rid-from-envelope',
        ] + $extra, $headers);
    }

    /** The last request sent — fails when there is none. */
    public function last(): Request
    {
        return $this->lastRequest ?? throw new \RuntimeException('FakeHttpClient: no request was sent.');
    }

    /** The JSON body of the last request, decoded. */
    public function lastJson(): mixed
    {
        $body = $this->lastRequest?->body;

        return $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
