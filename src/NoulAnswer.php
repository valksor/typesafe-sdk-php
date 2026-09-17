<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class NoulAnswer implements Answer
{
    public function __construct(public float $noul) {}

    public function type(): string
    {
        return 'noul';
    }
}
