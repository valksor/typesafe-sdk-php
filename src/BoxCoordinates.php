<?php

declare(strict_types=1);

namespace TypeSafe;

/** A box's edges as fractions of the image's height (top, bottom) and width (left, right). */
final readonly class BoxCoordinates
{
    public function __construct(
        public float $top,
        public float $bottom,
        public float $left,
        public float $right,
    ) {}
}
