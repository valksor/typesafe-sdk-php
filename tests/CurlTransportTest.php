<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\TestCase;
use TypeSafe\CurlTransport;

final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testReusesConnectionAndDoesNotLeakRequestState(): void
    {
        $port = $this->startServer();
        $url = sprintf('http://127.0.0.1:%d/', $port);
        $transport = new CurlTransport();

        $first = $transport->send('POST', $url, ['Content-Type' => 'application/json'], '{"a":1}', 5.0);
        $second = $transport->send('GET', $url, [], null, 5.0);

        $one = json_decode($first->body, true, flags: JSON_THROW_ON_ERROR);
        $two = json_decode($second->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($one);
        self::assertIsArray($two);
        self::assertSame(['POST', '{"a":1}', 'application/json'], [$one['method'], $one['body'], $one['content_type']]);
        // The second request is a bare GET: the first request's method, body and headers are gone.
        self::assertSame(['GET', '', null], [$two['method'], $two['body'], $two['content_type']]);
        // Both requests arrived on the same server-side connection, so the second send reused it.
        self::assertSame($one['connection'], $two['connection']);
    }

    private function startServer(): int
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::markTestSkipped('Unable to reserve a local port.');
        }
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/keepalive_server.php', (string) $port],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        if ($server === false) {
            self::markTestSkipped('Unable to start the local keep-alive server.');
        }
        $this->server = $server;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe !== false) {
                fclose($probe);

                return $port;
            }
            usleep(100_000);
        }
        self::markTestSkipped('The local keep-alive server did not start.');
    }
}
