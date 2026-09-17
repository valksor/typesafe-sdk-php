<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class ModelsResponse
{
    /** @param list<Model> $models */
    public function __construct(
        public array $models,
        public ResponseMeta $meta,
    ) {}
}
