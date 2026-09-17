<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class Model
{
    public function __construct(
        public string $name,
        public string $description,
        public string $releaseDate,
    ) {}
}
