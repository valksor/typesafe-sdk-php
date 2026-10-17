<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class BoxAnswer implements Answer
{
    /** @param ?float $confidence From 0 to 1, or null when the model does not provide it. */
    public function __construct(
        public BoxCoordinates $box,
        public ?float $confidence,
    ) {}

    public function type(): string
    {
        return 'box';
    }
}
