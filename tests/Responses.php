<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use TypeSafe\HttpResponse;

final class Responses
{
    /** @param array<string, mixed> $answers */
    public static function systemOne(array $answers = []): HttpResponse
    {
        return new HttpResponse(200, [], json_encode([
            'model' => 'vision',
            'answers' => $answers === [] ? new \stdClass() : $answers,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR));
    }
}
