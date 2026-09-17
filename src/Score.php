<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class Score implements Question
{
    /** @var list<mixed> */
    public array $criteria;

    /** @param array<array-key, mixed> $criteria Ordered descriptions indexed from zero. */
    public function __construct(
        public mixed $instructions,
        array $criteria,
    ) {
        if (!array_is_list($criteria)) {
            throw new TypeSafeException('Score criteria must be a list indexed from zero.');
        }
        if (count($criteria) < 2) {
            throw new TypeSafeException('Score questions require at least two criteria.');
        }
        $this->criteria = $criteria;
    }

    public function type(): string
    {
        return 'score';
    }

    public function validate(string $name): void
    {}

    /** @return array{type: string, instructions: mixed, criteria: list<mixed>} */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ];
    }
}
