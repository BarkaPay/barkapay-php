<?php

declare(strict_types=1);

namespace BarkaPay\Tests;

use BarkaPay\Exception\NetworkException;
use BarkaPay\Http\CurlClient;
use BarkaPay\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The real transport against a local `php -S` server: a hostile or broken
 * server must not be able to exhaust the memory of the integration.
 */
final class CurlClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('proc_open') || !\function_exists('curl_init')) {
            return;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        $name = (string) stream_socket_get_name($socket, false);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $router = __DIR__ . '/Support/oversized-server.php';
        $process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . self::$port, $router],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            return;
        }
        self::$server = $process;

        for ($i = 0; $i < 100; ++$i) {
            $probe = @fsockopen('127.0.0.1', self::$port);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function get(string $path): \BarkaPay\Http\Response
    {
        if (!\is_resource(self::$server)) {
            self::markTestSkipped('Cannot start a local PHP server here.');
        }

        return (new CurlClient(20.0, 5.0))->send(
            new Request('GET', 'http://127.0.0.1:' . self::$port . $path, ['Accept' => 'application/json']),
        );
    }

    public function testANormalResponseStillComesThrough(): void
    {
        $response = $this->get('/small');

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"ok":true', $response->body);
    }

    public function testAnOversizedStreamedBodyIsCutOff(): void
    {
        try {
            $this->get('/streamed');
            self::fail('Expected NetworkException.');
        } catch (NetworkException $e) {
            self::assertStringContainsString('maximum accepted size', $e->getMessage());
        }
    }

    public function testAnOversizedAnnouncedBodyIsRefused(): void
    {
        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('maximum accepted size');

        $this->get('/announced');
    }
}
