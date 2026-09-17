<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use TypeSafe\HttpResponse;
use TypeSafe\Transport;

final class FakeTransport implements Transport
{
    /** @var list<HttpResponse|\Throwable> */
    private array $responses;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $requests = [];

    public function __construct(HttpResponse|\Throwable ...$responses)
    {
        $this->responses = [];
        foreach ($responses as $response) {
            $this->responses[] = $response;
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        $response = array_shift($this->responses) ?? throw new \RuntimeException('No fake response queued.');
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }
}
