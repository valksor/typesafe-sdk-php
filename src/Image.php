<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * An image asset backed by image file bytes or by the path of an image file.
 *
 * An image is read once, on first use, and reused by every request that sends the same object. Use
 * an image inside a request's state, instructions or criteria; it is sent as an upload and replaced
 * with its unique reference. Casting it to a string gives that reference, for use inside text.
 */
final class Image implements \JsonSerializable, \Stringable
{
    private const CONTENT_TYPES = ["\x89PNG\r\n\x1a\n" => 'image/png', "\xff\xd8\xff" => 'image/jpeg'];
    private const UNKNOWN_CONTENT_TYPE = 'application/octet-stream';

    /**
     * The context of the request being encoded, read by jsonSerialize(): with it, a passed image
     * encodes as its alias and any other image as its reference, which is recorded as found.
     */
    private static ?ImageReferences $references = null;

    private readonly string $reference;

    /** @var array{contentType: string, data: string}|null */
    private ?array $resolution = null;

    private function __construct(private readonly ?string $bytes, private readonly ?string $path)
    {
        $this->reference = '<image:' . bin2hex(random_bytes(16)) . '>';
    }

    /** Image file bytes, such as the contents of a PNG or JPEG file. */
    public static function fromBytes(string $data): self
    {
        return new self($data, null);
    }

    /**
     * An image file, read when the image is first used. The path is a local file read: never pass an
     * untrusted path.
     */
    public static function fromFile(string $path): self
    {
        return new self(null, $path);
    }

    /**
     * Image file bytes as base64 text, such as an upload. Whitespace, such as the line breaks of
     * wrapped base64, is ignored.
     *
     * @throws TypeSafeException The data isn't valid base64 text.
     */
    public static function fromBase64(string $data): self
    {
        $text = preg_match('/[^\x00-\x7f]/', $data) === 1 ? '' : preg_replace('/[\s\x1c-\x1f]+/', '', $data);
        if ($text === null || $text === '') {
            throw new TypeSafeException('Image data must contain non-empty ASCII base64 text.');
        }
        // base64_decode() in strict mode accepts missing padding, which a base64 decoder must not.
        $decoded = base64_decode($text, true);
        if ($decoded === false || strlen($text) % 4 !== 0) {
            throw new TypeSafeException('Image data must contain valid base64 text.');
        }

        return new self($decoded, null);
    }

    /** The unique reference to this image, for using it inside text. */
    public function __toString(): string
    {
        return $this->reference;
    }

    public function jsonSerialize(): string
    {
        $references = self::$references;
        if ($references === null) {
            return $this->reference;
        }
        $alias = $references->aliases[spl_object_id($this)] ?? null;
        if ($alias !== null) {
            return $alias;
        }
        $references->found[$this->reference] = $this;

        return $this->reference;
    }

    /**
     * Runs $encode with a fresh encoding context that is cleared afterwards, even on failure.
     *
     * @internal
     *
     * @template T
     *
     * @param array<int, string> $aliases Aliases of the passed images, by spl_object_id().
     * @param \Closure(ImageReferences): T $encode
     *
     * @return T
     */
    public static function collecting(array $aliases, \Closure $encode): mixed
    {
        $previous = self::$references;
        $references = new ImageReferences($aliases);
        self::$references = $references;
        try {
            return $encode($references);
        } finally {
            self::$references = $previous;
        }
    }

    /** @internal */
    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * Reads and validates the image once; a failure is not cached.
     *
     * @internal
     *
     * @return array{contentType: string, data: string}
     *
     * @throws TypeSafeException The data can't be read or is empty.
     */
    public function resolve(): array
    {
        if ($this->resolution === null) {
            $data = $this->path !== null ? self::read($this->path) : (string) $this->bytes;
            if ($data === '') {
                throw new TypeSafeException('Image data is empty.');
            }
            $this->resolution = ['contentType' => self::contentType($data), 'data' => $data];
        }

        return $this->resolution;
    }

    private static function read(string $path): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
            throw self::readError($path, 'URL wrappers are not supported');
        }
        if (!is_file($path)) {
            throw self::readError($path, file_exists($path) ? 'not a regular file' : 'No such file or directory');
        }
        // Capture the warning text with a temporary handler: since PHP 8, the @ operator still
        // calls user error handlers, which may turn the warning into an exception.
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $data = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        if ($data === false) {
            $marker = 'Failed to open stream: ';
            $position = $warning === null ? false : strpos($warning, $marker);

            throw self::readError($path, $warning === null ? 'unknown error' : ($position === false ? $warning : substr($warning, $position + strlen($marker))));
        }

        return $data;
    }

    private static function readError(string $path, string $reason): TypeSafeException
    {
        return new TypeSafeException(sprintf('Image file "%s" could not be read: %s.', $path, $reason));
    }

    private static function contentType(string $data): string
    {
        foreach (self::CONTENT_TYPES as $signature => $contentType) {
            if (str_starts_with($data, (string) $signature)) {
                return $contentType;
            }
        }

        return self::UNKNOWN_CONTENT_TYPE;
    }
}
