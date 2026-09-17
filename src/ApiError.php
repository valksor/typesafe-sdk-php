<?php

declare(strict_types=1);

namespace TypeSafe;

class ApiError extends TypeSafeException
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly mixed $body,
        public readonly array $headers,
        public readonly ?string $requestId,
        ?string $message = null,
    ) {
        $description = $message ?? self::describe($body);
        $suffix = $requestId === null ? '' : sprintf(' (request_id=%s)', $requestId);
        parent::__construct(sprintf('%d %s%s', $statusCode, $description, $suffix));
    }

    private static function describe(mixed $body): string
    {
        if ($body === null) {
            return 'status code (no body)';
        }
        if (is_string($body) && $body !== '') {
            return self::truncate($body);
        }
        if (is_array($body)) {
            foreach (['error', 'message', 'detail'] as $key) {
                $value = $body[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                if (is_array($value) && is_string($value['message'] ?? null)) {
                    return $value['message'];
                }
            }
            if (is_array($body['detail'] ?? null)) {
                $validation = self::validationMessage($body['detail']);
                if ($validation !== '') {
                    return $validation;
                }
            }
        }
        try {
            return self::truncate(json_encode($body, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return 'unknown error';
        }
    }

    /** @param array<array-key, mixed> $entries */
    private static function validationMessage(array $entries): string
    {
        $messages = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['msg'] ?? null)) {
                continue;
            }
            $path = [];
            foreach (is_array($entry['loc'] ?? null) ? $entry['loc'] : [] as $part) {
                if ($part !== 'body' && (is_string($part) || is_int($part))) {
                    $path[] = (string) $part;
                }
            }
            $messages[] = ($path === [] ? '' : implode('.', $path) . ': ') . $entry['msg'];
        }
        return implode('; ', $messages);
    }

    private static function truncate(string $value): string
    {
        return strlen($value) > 200 ? substr($value, 0, 200) . '…' : $value;
    }
}
