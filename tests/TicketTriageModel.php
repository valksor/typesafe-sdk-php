<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use TypeSafe\ResponseModel;
use TypeSafe\SystemOneResponse;
use TypeSafe\Usage;

/**
 * Test fixture: a typed model built from a System One response, lifting three
 * named answers into its own properties.
 */
final readonly class TicketTriageModel implements ResponseModel
{
    public function __construct(
        public float $spam,
        public string $team,
        public float $tone,
        public string $model,
        public Usage $usage,
    ) {}

    public static function fromSystemOne(SystemOneResponse $response): static
    {
        $spam = $response->noul('spam')
            ?? throw new \UnexpectedValueException('missing or mistyped answer "spam"');
        $team = $response->choice('team')
            ?? throw new \UnexpectedValueException('missing or mistyped answer "team"');
        $tone = $response->score('tone')
            ?? throw new \UnexpectedValueException('missing or mistyped answer "tone"');

        return new self(
            spam: $spam->noul,
            team: $team->choice,
            tone: $tone->score,
            model: $response->model,
            usage: $response->usage,
        );
    }
}
