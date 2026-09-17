<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class Choice implements Question
{
    /** @var array<string, mixed> */
    public array $criteria;

    /**
     * @param array<array-key, mixed> $criteria Labels mapped to optional descriptions.
     */
    public function __construct(
        public mixed $instructions,
        array $criteria,
    ) {
        $normalized = [];
        foreach ($criteria as $label => $description) {
            if (!is_string($label)) {
                throw new TypeSafeException('Choice criteria must be a map of string labels.');
            }
            $normalized[$label] = $description;
        }
        $this->criteria = $normalized;
    }

    public function type(): string
    {
        return 'choice';
    }

    public function validate(string $name): void
    {}

    /** @return array{type: string, instructions: mixed, criteria: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ];
    }
}
