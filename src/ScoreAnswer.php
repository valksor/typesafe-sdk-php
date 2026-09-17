<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class ScoreAnswer implements Answer
{
    /**
     * @param array<int, mixed> $legend
     * @param array<int, float> $probabilities
     */
    public function __construct(
        public float $score,
        public float $confidence,
        public array $legend,
        public array $probabilities,
    ) {}

    public function type(): string
    {
        return 'score';
    }
}
