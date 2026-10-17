<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * The encoding context of one request: which images were passed as assets (by object id, with their
 * alias) and which others were serialized as their reference.
 *
 * @internal
 */
final class ImageReferences
{
    /** @var array<string, Image> Images serialized as their reference, by reference. */
    public array $found = [];

    /** @param array<int, string> $aliases Aliases of the images passed in `assets`, by spl_object_id(). */
    public function __construct(public readonly array $aliases) {}
}
