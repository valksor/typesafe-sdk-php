<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use TypeSafe\ResponseModel;
use TypeSafe\SystemOneResponse;

/**
 * Test fixture whose factory throws an exception type the SDK does not catch, to
 * prove such exceptions propagate raw rather than being rewrapped.
 */
final readonly class ThrowingModel implements ResponseModel
{
    public function __construct(public string $model) {}

    public static function fromSystemOne(SystemOneResponse $response): static
    {
        throw new \LogicException('bug in model, not a validation failure');
    }
}
