<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class RequestOptions
{
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public ?float $timeout = null,
        public ?RetryPolicy $retry = null,
        array $headers = [],
    ) {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new TypeSafeException('timeout must be a positive finite number of seconds.');
        }
        $this->headers = $headers;
    }
}
