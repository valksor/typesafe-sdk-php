<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class Models
{
    public function __construct(private Client $client) {}

    public function list(?RequestOptions $options = null): ModelsResponse
    {
        return $this->client->listModels($options);
    }
}
