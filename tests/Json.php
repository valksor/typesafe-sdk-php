<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

final class Json
{
    /** @return array<string, mixed> */
    public static function object(?string $body): array
    {
        $data = json_decode($body ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
