<?php

declare(strict_types=1);

namespace TypeSafe;

final class RateLimitError extends ApiError
{
    public ?float $retryAfterMilliseconds = null;
}
