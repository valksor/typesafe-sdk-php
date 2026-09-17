<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class ChoiceAnswer implements Answer
{
    /** @param array<string, float> $probabilities */
    public function __construct(
        public string $choice,
        public float $confidence,
        public array $probabilities,
    ) {}

    public function type(): string
    {
        return 'choice';
    }
}
