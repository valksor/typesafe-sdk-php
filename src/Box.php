<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * A question that locates what its instructions describe in the request's image.
 */
final readonly class Box implements Question
{
    public function __construct(public mixed $instructions = null) {}

    public function type(): string
    {
        return 'box';
    }

    public function validate(string $name): void {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $question = ['type' => $this->type()];
        if ($this->instructions !== null) {
            $question['instructions'] = $this->instructions;
        }

        return $question;
    }
}
