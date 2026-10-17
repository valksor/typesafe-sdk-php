<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * Request body compression.
 *
 * @internal
 */
final class Compression
{
    /** Bodies below this size fit in a single TCP segment, so compressing them saves no round trips and only costs CPU. */
    public const MIN_BODY_LENGTH = 1024;

    public const ENCODING = 'zstd';

    /** @var (\Closure(string): string)|false|null Test seam: replaces the extension's compressor, or `false` to simulate its absence. */
    private static \Closure|false|null $compressor = null;

    /**
     * Resolves the `compression` client option to the `Content-Encoding` to send large bodies with.
     *
     * @throws TypeSafeException The option is invalid, or zstd is requested without ext-zstd.
     */
    public static function resolve(string|false|null $option): ?string
    {
        if ($option === null) {
            return self::available() ? self::ENCODING : null;
        }
        if ($option === false) {
            return null;
        }
        if ($option !== self::ENCODING) {
            throw new TypeSafeException("compression must be 'zstd', false, or null.");
        }
        if (!self::available()) {
            throw new TypeSafeException('zstd compression requires the zstd PHP extension (ext-zstd).');
        }

        return self::ENCODING;
    }

    /** @throws TypeSafeException */
    public static function compress(string $body): string
    {
        $compressor = self::$compressor;
        if ($compressor instanceof \Closure) {
            return $compressor($body);
        }
        $function = 'zstd_compress';
        $compressed = $compressor === false || !function_exists($function) ? false : $function($body);
        if (!is_string($compressed)) {
            throw new TypeSafeException('The request body could not be compressed with zstd.');
        }

        return $compressed;
    }

    /**
     * Replaces the compressor, so tests can exercise the compression logic without ext-zstd; `false`
     * simulates its absence and `null` restores the extension.
     *
     * @param (\Closure(string): string)|false|null $compressor
     */
    public static function useCompressor(\Closure|false|null $compressor): void
    {
        self::$compressor = $compressor;
    }

    private static function available(): bool
    {
        return self::$compressor === false ? false : (self::$compressor !== null || function_exists('zstd_compress'));
    }
}
