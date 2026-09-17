<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class NoulCriteria implements \JsonSerializable
{
    public function __construct(
        public mixed $true = null,
        public mixed $false = null,
    ) {}

    /** @return array{true: mixed, false: mixed} */
    public function jsonSerialize(): array
    {
        return ['true' => $this->true, 'false' => $this->false];
    }
}
