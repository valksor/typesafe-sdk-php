<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * `multipart/form-data` request bodies (RFC 7578).
 *
 * @internal
 */
final class Multipart
{
    /**
     * Encodes the parts, returning the body's content type and bytes. Names and filenames must be
     * ASCII without quotes, as they are written unescaped.
     *
     * @param list<array{name: string, contentType: string, content: string, filename: ?string}> $parts
     *
     * @return array{string, string}
     */
    public static function encode(array $parts): array
    {
        // A random boundary, like browsers and cURL use, is practically certain not to occur in the content.
        $boundary = bin2hex(random_bytes(16));
        $body = '';
        foreach ($parts as $part) {
            $disposition = sprintf('form-data; name="%s"', $part['name']);
            if ($part['filename'] !== null) {
                $disposition .= sprintf('; filename="%s"', $part['filename']);
            }
            $body .= "--{$boundary}\r\nContent-Disposition: {$disposition}\r\nContent-Type: {$part['contentType']}\r\n\r\n{$part['content']}\r\n";
        }

        return ["multipart/form-data; boundary={$boundary}", $body . "--{$boundary}--\r\n"];
    }
}
