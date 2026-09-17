<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class ResponseMeta
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public ?string $requestId,
    ) {}
}
