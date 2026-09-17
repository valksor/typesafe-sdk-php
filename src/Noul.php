<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class Noul implements Question
{
    public function __construct(
        public mixed $instructions = null,
        public ?NoulCriteria $criteria = null,
    ) {}

    public function type(): string
    {
        return 'noul';
    }

    public function validate(string $name): void {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $question = ['type' => $this->type()];
        if ($this->instructions !== null) {
            $question['instructions'] = $this->instructions;
        }
        if ($this->criteria !== null) {
            $question['criteria'] = $this->criteria;
        }

        return $question;
    }
}
