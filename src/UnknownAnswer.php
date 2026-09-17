<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class UnknownAnswer implements Answer
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        private string $answerType,
        public array $raw,
    ) {}

    public function type(): string
    {
        return $this->answerType;
    }
}
