<?php

declare(strict_types=1);

namespace TypeSafe;

final class ResponseValidationError extends TypeSafeException
{
    public function __construct(
        string $message,
        public readonly HttpResponse $response,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
