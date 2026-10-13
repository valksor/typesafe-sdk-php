<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * Validation shared by typed questions and raw question arrays, so both reject the same inputs.
 *
 * @internal
 */
final class QuestionRules
{
    public static function choice(string $name, mixed $criteria): void
    {
        // A decoded JSON object (stdClass) is valid criteria, so only null, [] and {} are empty.
        if ($criteria === null || $criteria === [] || ($criteria instanceof \stdClass && get_object_vars($criteria) === [])) {
            throw new TypeSafeException(sprintf('Choice question "%s" has no criteria; at least one choice is required.', $name));
        }
    }

    public static function score(string $name, mixed $criteria): void
    {
        if (!is_array($criteria) || $criteria === []) {
            throw new TypeSafeException(sprintf('Score question "%s" has no criteria; at least one score is required.', $name));
        }
    }

    public static function noul(string $name, mixed $instructions, mixed $criteria): void
    {
        $map = null;
        if ($criteria !== null) {
            $map = self::objectMap($criteria)
                ?? throw new TypeSafeException(sprintf('Noul question "%s" criteria must be an object.', $name));
        }
        if ($instructions === null && !self::hasValue($map ?? [])) {
            throw new TypeSafeException(sprintf('Noul question "%s" has neither instructions nor criteria; at least one is required.', $name));
        }
    }

    /**
     * The key/value pairs of a value that encodes as a JSON object, or null when it does not.
     * An empty array counts as an (empty) object; a non-empty list does not.
     *
     * @return array<array-key, mixed>|null
     */
    private static function objectMap(mixed $value): ?array
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            return $value;
        }

        return null;
    }

    /** @param array<array-key, mixed> $map */
    private static function hasValue(array $map): bool
    {
        foreach ($map as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }
}
