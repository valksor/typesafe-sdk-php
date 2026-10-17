<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use TypeSafe\ResponseModel;
use TypeSafe\SystemOneResponse;

final readonly class LocatedModel implements ResponseModel
{
    public function __construct(public float $bottom) {}

    public static function fromSystemOne(SystemOneResponse $response): static
    {
        $box = $response->box('dog') ?? throw new \UnexpectedValueException('missing answer "dog"');

        return new self($box->box->bottom);
    }
}
