<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TypeSafe\Client;
use TypeSafe\Compression;
use TypeSafe\HttpResponse;
use TypeSafe\Image;
use TypeSafe\Noul;
use TypeSafe\RetryPolicy;
use TypeSafe\TypeSafeException;

final class CompressionTest extends TestCase
{
    private const MARKER = 'COMPRESSED:';

    protected function tearDown(): void
    {
        Compression::useCompressor(null);
    }

    /** @return iterable<string, array{string|false|null, ?string}> */
    public static function options(): iterable
    {
        yield 'default' => [null, 'zstd'];
        yield 'zstd' => ['zstd', 'zstd'];
        yield 'opt-out' => [false, null];
    }

    #[DataProvider('options')]
    public function testCompressesBodiesOfAtLeastOneKiB(string|false|null $option, ?string $encoding): void
    {
        $this->useFakeCompressor();
        $transport = new FakeTransport(Responses::systemOne(), new HttpResponse(200, [], '{"models":[]}'));
        $client = new Client(apiKey: 'test', transport: $transport, compression: $option);
        $state = str_repeat('x', Compression::MIN_BODY_LENGTH);

        $client->systemOne(state: $state, questions: ['q' => new Noul('?')]);
        $client->models->list();

        $request = $transport->requests[0];
        self::assertSame($encoding, $request['headers']['Content-Encoding'] ?? null);
        $json = $this->plainBody($request['body'], $encoding !== null);
        self::assertSame(Compression::MIN_BODY_LENGTH, strlen($json) - strlen('{"state":"","questions":{"q":{"type":"noul","instructions":"?"}},"model":"jev-latest"}'));
        self::assertSame($state, Json::object($json)['state']);
        // Requests without a body are never compressed.
        self::assertArrayNotHasKey('Content-Encoding', $transport->requests[1]['headers']);
        self::assertNull($transport->requests[1]['body']);
    }

    public function testBodiesBelowTheThresholdAreNotCompressed(): void
    {
        $this->useFakeCompressor();
        $transport = new FakeTransport(Responses::systemOne(), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport, compression: 'zstd');
        $overhead = strlen('{"state":"","questions":{"q":{"type":"noul","instructions":"?"}},"model":"jev-latest"}');

        $client->systemOne(state: str_repeat('x', Compression::MIN_BODY_LENGTH - $overhead - 1), questions: ['q' => new Noul('?')]);
        $client->systemOne(state: str_repeat('x', Compression::MIN_BODY_LENGTH - $overhead), questions: ['q' => new Noul('?')]);

        self::assertSame(Compression::MIN_BODY_LENGTH - 1, strlen((string) $transport->requests[0]['body']));
        self::assertArrayNotHasKey('Content-Encoding', $transport->requests[0]['headers']);
        self::assertSame('zstd', $transport->requests[1]['headers']['Content-Encoding']);
        self::assertStringStartsWith(self::MARKER, (string) $transport->requests[1]['body']);
    }

    public function testCompressedMultipartBodyDecompressesAndParsesBack(): void
    {
        $this->useFakeCompressor();
        $transport = new FakeTransport(Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport);

        $client->systemOne(state: '<photo>', questions: ['q' => new Noul('?')], assets: ['<photo>' => Image::fromBytes(TestImages::PNG . str_repeat('x', 2000))]);

        $request = $transport->requests[0];
        self::assertSame('zstd', $request['headers']['Content-Encoding']);
        // The multipart content type and the empty Expect header describe the uncompressed body.
        self::assertStringStartsWith('multipart/form-data; boundary=', $request['headers']['Content-Type']);
        self::assertSame('', $request['headers']['Expect']);
        $parts = MultipartParser::parse($request['headers']['Content-Type'], $this->plainBody($request['body'], true));
        self::assertSame(['request', 'asset-0'], array_column($parts, 'name'));
        self::assertSame(TestImages::PNG . str_repeat('x', 2000), $parts[1]['content']);
    }

    public function testRetriesSendTheSameCompressedBytes(): void
    {
        $calls = 0;
        Compression::useCompressor(static function (string $body) use (&$calls): string {
            ++$calls;

            return self::MARKER . $body;
        });
        $transport = new FakeTransport(new HttpResponse(500, [], '{}'), new HttpResponse(500, [], '{}'), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport, retry: new RetryPolicy(maxRetries: 2, backoffInitial: 0, backoffMax: 0));

        $client->systemOne(state: str_repeat('x', 2000), questions: ['q' => new Noul('?')]);

        self::assertSame(1, $calls);
        self::assertCount(3, $transport->requests);
        self::assertSame($transport->requests[0]['body'], $transport->requests[1]['body']);
        self::assertSame($transport->requests[0]['body'], $transport->requests[2]['body']);
        foreach ($transport->requests as $request) {
            self::assertSame('zstd', $request['headers']['Content-Encoding']);
        }
        self::assertSame('1', $transport->requests[1]['headers']['X-TypeSafe-Retry-Count']);
    }

    public function testWithoutTheExtensionTheDefaultSendsUncompressedAndZstdThrows(): void
    {
        Compression::useCompressor(false);
        $transport = new FakeTransport(Responses::systemOne());

        (new Client(apiKey: 'test', transport: $transport))->systemOne(state: str_repeat('x', 2000), questions: ['q' => new Noul('?')]);

        self::assertArrayNotHasKey('Content-Encoding', $transport->requests[0]['headers']);
        self::assertStringStartsWith('{"state":', (string) $transport->requests[0]['body']);
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('zstd compression requires the zstd PHP extension (ext-zstd)');
        new Client(apiKey: 'test', transport: $transport, compression: 'zstd');
    }

    public function testOptingOutNeedsNoExtension(): void
    {
        Compression::useCompressor(false);

        self::assertInstanceOf(Client::class, new Client(apiKey: 'test', transport: new FakeTransport(), compression: false));
    }

    public function testInvalidOptionIsRejected(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage("compression must be 'zstd', false, or null.");
        new Client(apiKey: 'test', transport: new FakeTransport(), compression: 'brotli');
    }

    #[RequiresPhpExtension('zstd')]
    public function testRealZstdRoundTrips(): void
    {
        $transport = new FakeTransport(Responses::systemOne(), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport);
        $state = str_repeat('x', 5000);

        $client->systemOne(state: $state, questions: ['q' => new Noul('?')]);
        $client->systemOne(state: $state, questions: ['q' => new Noul('?')], assets: ['<photo>' => Image::fromBytes(TestImages::PNG . str_repeat('x', 5000))]);

        foreach ($transport->requests as $request) {
            self::assertSame('zstd', $request['headers']['Content-Encoding']);
            self::assertIsString($request['body']);
            // Zstandard frames start with the magic number 0xFD2FB528.
            self::assertStringStartsWith("\x28\xb5\x2f\xfd", $request['body']);
        }
        $plain = zstd_uncompress((string) $transport->requests[0]['body']);
        self::assertIsString($plain);
        self::assertSame($state, Json::object($plain)['state']);
        self::assertLessThan(5000, strlen((string) $transport->requests[0]['body']));
        $multipart = zstd_uncompress((string) $transport->requests[1]['body']);
        self::assertIsString($multipart);
        $parts = MultipartParser::parse($transport->requests[1]['headers']['Content-Type'], $multipart);
        self::assertSame(['request', 'asset-0'], array_column($parts, 'name'));
        self::assertSame(TestImages::PNG . str_repeat('x', 5000), $parts[1]['content']);
    }

    #[RequiresPhpExtension('zstd')]
    public function testZstdIsTheDefaultWhenTheExtensionIsLoaded(): void
    {
        $transport = new FakeTransport(Responses::systemOne());

        (new Client(apiKey: 'test', transport: $transport))->systemOne(state: str_repeat('x', 2000), questions: ['q' => new Noul('?')]);

        self::assertSame('zstd', $transport->requests[0]['headers']['Content-Encoding']);
    }

    private function useFakeCompressor(): void
    {
        Compression::useCompressor(static fn (string $body): string => self::MARKER . $body);
    }

    private function plainBody(?string $body, bool $compressed): string
    {
        self::assertIsString($body);
        if (!$compressed) {
            return $body;
        }
        self::assertStringStartsWith(self::MARKER, $body);

        return substr($body, strlen(self::MARKER));
    }
}
