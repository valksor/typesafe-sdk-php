<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class SystemOneResponse
{
    /** @param array<string, Answer> $answers */
    public function __construct(
        public string $model,
        public array $answers,
        public Usage $usage,
        public ResponseMeta $meta,
    ) {}

    public function noul(string $name): ?NoulAnswer
    {
        $answer = $this->answers[$name] ?? null;
        return $answer instanceof NoulAnswer ? $answer : null;
    }

    public function choice(string $name): ?ChoiceAnswer
    {
        $answer = $this->answers[$name] ?? null;
        return $answer instanceof ChoiceAnswer ? $answer : null;
    }

    public function score(string $name): ?ScoreAnswer
    {
        $answer = $this->answers[$name] ?? null;
        return $answer instanceof ScoreAnswer ? $answer : null;
    }
}
