<?php

declare(strict_types=1);

namespace TypeSafe;

interface Question extends \JsonSerializable
{
    public function type(): string;

    public function validate(string $name): void;
}
