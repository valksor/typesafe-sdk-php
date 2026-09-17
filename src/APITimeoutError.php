<?php

declare(strict_types=1);

namespace TypeSafe;

final class APITimeoutError extends APIConnectionError
{
    public function __construct(
        public readonly float $timeout,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Request timed out after %gs.', $timeout), 0, $previous);
    }
}
