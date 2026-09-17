<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class HttpResponse
{
    /** @param array<string, list<string>> $headers Header names must be lowercase. */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function meta(): ResponseMeta
    {
        return new ResponseMeta($this->statusCode, $this->headers, $this->header('x-typesafe-request-id'));
    }
}
