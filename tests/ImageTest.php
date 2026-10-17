<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeSafe\Image;
use TypeSafe\TypeSafeException;

final class ImageTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                chmod($file, 0o600);
                unlink($file);
            }
        }
        $this->files = [];
    }

    public function testReferenceIsUniqueAndStringifies(): void
    {
        $image = Image::fromBytes(TestImages::PNG);

        self::assertMatchesRegularExpression('/^<image:[0-9a-f]{32}>$/', (string) $image);
        self::assertSame((string) $image, "{$image}");
        self::assertNotSame((string) $image, (string) Image::fromBytes(TestImages::PNG));
        self::assertSame(json_encode((string) $image), json_encode($image));
    }

    #[DataProvider('contentTypes')]
    public function testSniffsContentTypeFromTheSignature(string $data, string $contentType): void
    {
        self::assertSame(['contentType' => $contentType, 'data' => $data], Image::fromBytes($data)->resolve());
    }

    /** @return iterable<string, array{string, string}> */
    public static function contentTypes(): iterable
    {
        yield 'png' => [TestImages::PNG, 'image/png'];
        yield 'jpeg' => [TestImages::JPEG, 'image/jpeg'];
        yield 'gif is sent as binary' => ['GIF89a image data', 'application/octet-stream'];
        yield 'short png prefix' => ["\x89PNG", 'application/octet-stream'];
    }

    public function testEmptyDataIsRejected(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Image data is empty.');
        Image::fromBytes('')->resolve();
    }

    public function testFromBase64DecodesWrappedText(): void
    {
        $data = str_repeat(TestImages::PNG, 20);

        foreach ([base64_encode($data), chunk_split(base64_encode($data)), base64_encode($data) . "\r\n", ' ' . base64_encode($data) . "\t"] as $text) {
            self::assertSame($data, Image::fromBase64($text)->resolve()['data']);
        }
    }

    #[DataProvider('invalidBase64')]
    public function testFromBase64RejectsInvalidText(string $data, string $message): void
    {
        try {
            Image::fromBase64($data);
            self::fail('Expected a TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBase64(): iterable
    {
        $empty = 'Image data must contain non-empty ASCII base64 text.';
        $invalid = 'Image data must contain valid base64 text.';
        yield 'empty' => ['', $empty];
        yield 'whitespace only' => [" \n", $empty];
        yield 'non-breaking space' => ["iVBO\xc2\xa0Rw0K", $empty];
        yield 'non-ascii' => ['iVBORw0KGgoé', $empty];
        yield 'bad characters' => ['!!!!' . base64_encode(TestImages::PNG), $invalid];
        yield 'missing padding' => ['iVBORw0', $invalid];
        yield 'misplaced padding' => ['iVB=Rw0K', $invalid];
    }

    public function testFromFileReadsLazilyAndResolvesOnce(): void
    {
        $path = $this->tempFile('');
        $image = Image::fromFile($path);
        // The file is read when the image is first used, not when it is created.
        file_put_contents($path, TestImages::PNG);

        $first = $image->resolve();
        file_put_contents($path, TestImages::JPEG);

        self::assertSame('image/png', $first['contentType']);
        // Later calls reuse the first resolution, even after the file changes.
        self::assertSame($first, $image->resolve());
    }

    public function testResolutionFailureIsNotCached(): void
    {
        $path = $this->tempFile('');
        unlink($path);
        $image = Image::fromFile($path);

        try {
            $image->resolve();
            self::fail('Expected a TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertSame(sprintf('Image file "%s" could not be read: No such file or directory.', $path), $error->getMessage());
        }

        file_put_contents($path, TestImages::JPEG);
        self::assertSame('image/jpeg', $image->resolve()['contentType']);
    }

    public function testEmptyFileIsEmptyData(): void
    {
        $this->expectExceptionMessage('Image data is empty.');
        Image::fromFile($this->tempFile(''))->resolve();
    }

    public function testDirectoryIsNotARegularFile(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage(sprintf('Image file "%s" could not be read: not a regular file.', __DIR__));
        Image::fromFile(__DIR__)->resolve();
    }

    #[DataProvider('urlPaths')]
    public function testUrlWrappersAreRejected(string $path): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage(sprintf('Image file "%s" could not be read: URL wrappers are not supported.', $path));
        Image::fromFile($path)->resolve();
    }

    /** @return iterable<string, array{string}> */
    public static function urlPaths(): iterable
    {
        yield 'http' => ['http://example.com/photo.png'];
        yield 'uppercase scheme' => ['HTTPS://example.com/photo.png'];
        yield 'php stream' => ['php://stdin'];
        yield 'file url' => ['file:///etc/hostname'];
    }

    public function testReadFailuresStayTypeSafeExceptionsUnderAWarningsToExceptionsHandler(): void
    {
        $path = $this->tempFile(TestImages::PNG);
        chmod($path, 0o000);
        if (is_readable($path)) {
            self::markTestSkipped('File permissions are not enforced for this user.');
        }
        $handler = static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        };
        set_error_handler($handler);
        try {
            foreach ([$path, 'http://example.com/photo.png'] as $unreadable) {
                try {
                    Image::fromFile($unreadable)->resolve();
                    self::fail('Expected a TypeSafeException.');
                } catch (TypeSafeException $error) {
                    self::assertStringStartsWith(sprintf('Image file "%s" could not be read: ', $unreadable), $error->getMessage());
                }
            }
            // The caller's handler is back in place afterwards.
            self::assertSame($handler, set_error_handler(static fn (): bool => false));
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'typesafe-image');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
