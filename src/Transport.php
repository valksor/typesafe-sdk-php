<?php

declare(strict_types=1);

namespace TypeSafe;

interface Transport
{
    /** @param array<string, string> $headers */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse;
}
