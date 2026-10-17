<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

/** Splits a `multipart/form-data` body into its parts, in order. */
final class MultipartParser
{
    /**
     * @return list<array{name: string, headers: string, content: string}>
     */
    public static function parse(string $contentType, string $body): array
    {
        if (preg_match('/^multipart\/form-data; boundary=([0-9a-f]{32})$/', $contentType, $match) !== 1) {
            throw new \UnexpectedValueException(sprintf('Unexpected multipart content type "%s".', $contentType));
        }
        $chunks = explode('--' . $match[1], $body);
        if (array_shift($chunks) !== '' || array_pop($chunks) !== "--\r\n") {
            throw new \UnexpectedValueException('Malformed multipart body.');
        }
        $parts = [];
        foreach ($chunks as $chunk) {
            if (!str_starts_with($chunk, "\r\n") || !str_ends_with($chunk, "\r\n")) {
                throw new \UnexpectedValueException('Malformed multipart part.');
            }
            [$headers, $content] = explode("\r\n\r\n", substr($chunk, 2, -2), 2);
            if (preg_match('/name="([^"]+)"/', $headers, $name) !== 1) {
                throw new \UnexpectedValueException('A multipart part has no name.');
            }
            $parts[] = ['name' => $name[1], 'headers' => $headers, 'content' => $content];
        }

        return $parts;
    }
}
