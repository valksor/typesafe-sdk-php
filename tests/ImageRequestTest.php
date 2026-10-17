<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeSafe\Box;
use TypeSafe\Choice;
use TypeSafe\Client;
use TypeSafe\Image;
use TypeSafe\Noul;
use TypeSafe\RetryPolicy;
use TypeSafe\Score;
use TypeSafe\TypeSafeException;

final class ImageRequestTest extends TestCase
{
    private const QUESTIONS_KEY = 'q';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testImagesInStateAndQuestionsAreSentUnderTheirReferences(): void
    {
        $returned = Image::fromBytes(TestImages::PNG);
        $listing = Image::fromBytes(TestImages::JPEG);
        [$transport, $client] = $this->client();

        $client->systemOne(
            state: [['role' => 'user', 'msg' => ['Here is what came back:', $returned]], ['listing', $listing]],
            questions: [
                'same' => new Noul(['Is it the item in this photo?', $listing]),
                'condition' => new Choice(null, ['new' => $returned, 'used' => null]),
                'damage' => new Score(null, ['none', ['example' => [$returned]]]),
                'raw' => ['type' => 'noul', 'instructions' => $returned, 'criteria' => ['true' => $listing]],
                'locate' => new Box($returned),
            ],
        );

        $r = (string) $returned;
        $l = (string) $listing;
        $request = $this->multipart($transport);
        $body = $request['json'];
        self::assertSame([[ 'role' => 'user', 'msg' => ['Here is what came back:', $r]], ['listing', $l]], $body['state']);
        self::assertSame([
            'same' => ['type' => 'noul', 'instructions' => ['Is it the item in this photo?', $l]],
            'condition' => ['type' => 'choice', 'instructions' => null, 'criteria' => ['new' => $r, 'used' => null]],
            'damage' => ['type' => 'score', 'instructions' => null, 'criteria' => ['none', ['example' => [$r]]]],
            'raw' => ['type' => 'noul', 'instructions' => $r, 'criteria' => ['true' => $l]],
            'locate' => ['type' => 'box', 'instructions' => $r],
        ], $body['questions']);
        // Each image is sent once, in order of appearance.
        self::assertSame([$r, $l], array_keys($request['assets']));
        self::assertSame(TestImages::PNG, $request['assets'][$r]['content']);
        self::assertSame(TestImages::JPEG, $request['assets'][$l]['content']);
    }

    public function testImageAsWholeState(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();

        $client->systemOne(state: $image, questions: [self::QUESTIONS_KEY => new Noul('Is it damaged?')]);

        $request = $this->multipart($transport);
        self::assertSame((string) $image, $request['json']['state']);
        self::assertSame([(string) $image], array_keys($request['assets']));
    }

    public function testContentWithoutImagesIsPlainJson(): void
    {
        [$transport, $client] = $this->client();

        $client->systemOne(state: ['a' => [1, 'x', null, [true, 0.5]]], questions: [self::QUESTIONS_KEY => new Noul(['text' => ['a', 'b']])]);

        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
        self::assertArrayNotHasKey('Expect', $transport->requests[0]['headers']);
        $body = Json::object($transport->requests[0]['body']);
        self::assertArrayNotHasKey('assets', $body);
        self::assertSame(['a' => [1, 'x', null, [true, 0.5]]], $body['state']);
    }

    #[DataProvider('noAssets')]
    public function testNoPassedAssetsSendsNoAssetsKey(mixed $assets): void
    {
        [$transport, $client] = $this->client();

        // @phpstan-ignore argument.type
        $client->systemOne(state: 'text', questions: [self::QUESTIONS_KEY => new Noul('?')], assets: $assets);

        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
        self::assertArrayNotHasKey('assets', Json::object($transport->requests[0]['body']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function noAssets(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [[]];
    }

    public function testPassedAssetsComeFirstAndReplaceAliasesAnywhereInContent(): void
    {
        $inline = Image::fromBytes(TestImages::JPEG);
        [$transport, $client] = $this->client();

        $client->systemOne(
            state: ['Compare with <listing>.', $inline],
            questions: [self::QUESTIONS_KEY => new Noul('Is it damaged?')],
            assets: ['<listing>' => Image::fromBytes(TestImages::PNG)],
        );

        $request = $this->multipart($transport);
        self::assertSame(['<listing>', (string) $inline], array_keys($request['assets']));
        self::assertSame(TestImages::PNG, $request['assets']['<listing>']['content']);
        self::assertSame(['Compare with <listing>.', (string) $inline], $request['json']['state']);
    }

    public function testPlacedPassedImageIsSentOnlyUnderItsAlias(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();

        $client->systemOne(
            state: ['Compare <photo> with', $image],
            questions: [self::QUESTIONS_KEY => new Noul(['Is it damaged?', $image])],
            assets: ['<photo>' => $image],
        );

        $request = $this->multipart($transport);
        self::assertSame(['<photo>'], array_keys($request['assets']));
        self::assertSame(['Compare <photo> with', '<photo>'], $request['json']['state']);
        self::assertSame([self::QUESTIONS_KEY => ['type' => 'noul', 'instructions' => ['Is it damaged?', '<photo>']]], $request['json']['questions']);
        self::assertStringNotContainsString((string) $image, (string) $transport->requests[0]['body']);
    }

    public function testImageUnderSeveralNamesIsSentOncePerName(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();

        $client->systemOne(
            state: '<a> <b> <c>',
            questions: [self::QUESTIONS_KEY => new Noul('?')],
            assets: ['<a>' => $image, '<b>' => $image, '<c>' => Image::fromBase64(base64_encode(TestImages::JPEG))],
        );

        $request = $this->multipart($transport);
        self::assertSame(
            ['<a>' => ['type' => 'image', 'part' => 'asset-0'], '<b>' => ['type' => 'image', 'part' => 'asset-1'], '<c>' => ['type' => 'image', 'part' => 'asset-2']],
            $request['json']['assets'],
        );
        self::assertSame(['<a>', '<b>', '<c>'], array_keys($request['assets']));
        self::assertSame(TestImages::PNG, $request['assets']['<a>']['content']);
        self::assertSame(TestImages::PNG, $request['assets']['<b>']['content']);
        self::assertSame('image/png', $request['assets']['<b>']['contentType']);
        self::assertSame('image/jpeg', $request['assets']['<c>']['contentType']);
    }

    public function testMultipartLayoutAndHeaders(): void
    {
        [$transport, $client] = $this->client();

        $client->systemOne(state: '<photo>', questions: [self::QUESTIONS_KEY => new Noul('?')], assets: ['<photo>' => Image::fromBytes(TestImages::PNG)]);

        $headers = $transport->requests[0]['headers'];
        $parts = MultipartParser::parse($headers['Content-Type'], (string) $transport->requests[0]['body']);
        self::assertSame(['request', 'asset-0'], array_column($parts, 'name'));
        self::assertSame("Content-Disposition: form-data; name=\"request\"\r\nContent-Type: application/json", $parts[0]['headers']);
        self::assertSame("Content-Disposition: form-data; name=\"asset-0\"; filename=\"asset-0\"\r\nContent-Type: image/png", $parts[1]['headers']);
        // An empty Expect header stops cURL from waiting for 100-continue.
        self::assertArrayHasKey('Expect', $headers);
        self::assertSame('', $headers['Expect']);
        self::assertSame(['<photo>' => ['type' => 'image', 'part' => 'asset-0']], Json::object($parts[0]['content'])['assets']);
    }

    public function testAssetPayloadIsIdenticalOnRetry(): void
    {
        $transport = new FakeTransport(new \TypeSafe\HttpResponse(500, [], '{}'), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport, retry: new RetryPolicy(maxRetries: 1, backoffInitial: 0, backoffMax: 0), compression: false);

        $client->systemOne(state: '<photo>', questions: [self::QUESTIONS_KEY => new Noul('?')], assets: ['<photo>' => Image::fromBytes(TestImages::PNG)]);

        self::assertCount(2, $transport->requests);
        self::assertSame($transport->requests[0]['body'], $transport->requests[1]['body']);
        self::assertSame($transport->requests[0]['headers']['Content-Type'], $transport->requests[1]['headers']['Content-Type']);
    }

    #[DataProvider('overrides')]
    public function testExtraBodyAssetsSkipAssetHandling(mixed $override): void
    {
        $missing = Image::fromFile('/nonexistent/photo.png');
        [$transport, $client] = $this->client();

        // Overridden inputs are not read, encoded or validated.
        $client->systemOne(
            state: $missing,
            questions: [self::QUESTIONS_KEY => new Noul('?')],
            extraBody: ['assets' => $override],
            assets: ['<photo>' => $missing, '' => 'not an image'],
        );

        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
        $body = Json::object($transport->requests[0]['body']);
        self::assertArrayHasKey('assets', $body);
        self::assertSame($override, $body['assets']);
        // Passed images still encode as their alias, as upstream's serializer does.
        self::assertSame('<photo>', $body['state']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function overrides(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [[]];
        yield 'raw' => [['<photo>' => ['type' => 'image', 'format' => 'png', 'data_base64' => 'raw-override']]];
    }

    public function testTextReferencesAreSentWhenTheImageIsAttachedPassedOrPlaced(): void
    {
        $attached = Image::fromBytes(TestImages::PNG);
        $passed = Image::fromBytes(TestImages::JPEG);
        $placed = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();

        $client->attachAssets($attached, fn () => $client->systemOne(
            state: ["The customer says {$attached} arrived scratched.", $placed, "Compare {$placed} with {$passed}."],
            questions: [self::QUESTIONS_KEY => new Noul("Is {$passed} the item in {$attached}?")],
            assets: ['<photo>' => $passed],
        ));

        $request = $this->multipart($transport);
        // Passed assets first, then the others in order of appearance.
        self::assertSame(['<photo>', (string) $attached, (string) $placed, (string) $passed], array_keys($request['assets']));
        self::assertSame(
            ["The customer says {$attached} arrived scratched.", (string) $placed, "Compare {$placed} with {$passed}."],
            $request['json']['state'],
        );
    }

    public function testUnknownReferencesAreSentAsText(): void
    {
        $unattached = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();
        $state = "Look at {$unattached} and <image:" . str_repeat('0', 32) . '>';

        $client->systemOne(state: $state, questions: [self::QUESTIONS_KEY => new Noul('?')], assets: ['<photo>' => Image::fromBytes(TestImages::PNG)]);

        $request = $this->multipart($transport);
        self::assertSame($state, $request['json']['state']);
        self::assertSame(['<photo>'], array_keys($request['assets']));
    }

    public function testNamedImageFormattedIntoTextIsSentUnderBothNames(): void
    {
        $path = $this->tempFile(TestImages::PNG);
        $image = Image::fromFile($path);
        [$transport, $client] = $this->client();

        $client->systemOne(state: "<photo> is {$image}", questions: [self::QUESTIONS_KEY => new Noul('?')], assets: ['<photo>' => $image]);

        $request = $this->multipart($transport);
        self::assertSame(['<photo>', (string) $image], array_keys($request['assets']));
        self::assertSame(TestImages::PNG, $request['assets'][(string) $image]['content']);
    }

    public function testIntegerAliasesAreCastToStrings(): void
    {
        [$transport, $client] = $this->client();

        $client->systemOne(state: '0 and 1', questions: [self::QUESTIONS_KEY => new Noul('?')], assets: [Image::fromBytes(TestImages::PNG), Image::fromBytes(TestImages::JPEG)]);

        $request = $this->multipart($transport);
        self::assertSame(['0', '1'], array_map('strval', array_keys($request['assets'])));
        // Sequential numeric aliases still encode as a JSON object, not a list.
        self::assertStringContainsString('"assets":{"0":{"type":"image","part":"asset-0"},"1":', (string) $this->parts($transport)[0]['content']);
    }

    /**
     * @param array<array-key, mixed> $assets
     * @param non-empty-string $message
     */
    #[DataProvider('invalidAssets')]
    public function testInvalidAssetsAreRejectedBeforeTheNetwork(array $assets, string $message): void
    {
        [$transport, $client] = $this->client();

        try {
            $client->systemOne(state: '<photo>', questions: [self::QUESTIONS_KEY => new Noul('?')], assets: $assets);
            self::fail('Expected a TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertStringStartsWith($message, $error->getMessage());
        }
        self::assertSame([], $transport->requests);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidAssets(): iterable
    {
        yield 'empty alias' => [['' => Image::fromBytes(TestImages::PNG)], 'Asset aliases must be non-empty strings.'];
        yield 'blank alias' => [['  ' => Image::fromBytes(TestImages::PNG)], 'Asset aliases must be non-empty strings.'];
        yield 'bytes' => [['<photo>' => TestImages::PNG], 'Asset "<photo>" must be an asset.'];
        yield 'array' => [['<photo>' => ['type' => 'image']], 'Asset "<photo>" must be an asset.'];
        yield 'empty image' => [['<photo>' => Image::fromBytes('')], 'Asset "<photo>": Image data is empty.'];
        yield 'missing file' => [['<photo>' => Image::fromFile('missing.png')], 'Asset "<photo>": Image file "missing.png" could not be read'];
        yield 'url' => [['<photo>' => Image::fromFile('http://example.com/photo.png')], 'Asset "<photo>": Image file "http://example.com/photo.png" could not be read: URL wrappers are not supported.'];
    }

    public function testPlacedImageErrorsNameTheReference(): void
    {
        $image = Image::fromFile('missing.png');
        [$transport, $client] = $this->client();

        try {
            $client->systemOne(state: ['photos' => [$image]], questions: [self::QUESTIONS_KEY => new Noul('?')]);
            self::fail('Expected a TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertStringStartsWith(sprintf('Asset "%s": Image file "missing.png" could not be read', $image), $error->getMessage());
        }
        self::assertSame([], $transport->requests);
    }

    public function testFirstFailingAssetIsReported(): void
    {
        [, $client] = $this->client();

        $this->expectExceptionMessage('Asset "<missing>": Image file "missing.png" could not be read');
        $client->systemOne(
            state: '<ok> <missing> <empty>',
            questions: [self::QUESTIONS_KEY => new Noul('?')],
            assets: ['<ok>' => Image::fromBytes(TestImages::PNG), '<missing>' => Image::fromFile('missing.png'), '<empty>' => Image::fromBytes('')],
        );
    }

    public function testEncodingContextIsClearedAfterAnEncodeFailure(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client();

        try {
            $client->systemOne(state: ["\xff invalid utf-8", $image], questions: [self::QUESTIONS_KEY => new Noul('?')], assets: ['<photo>' => $image]);
            self::fail('Expected a TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertSame('The request body could not be encoded as JSON.', $error->getMessage());
        }

        // A leaked context would still encode the image as its alias.
        self::assertSame(json_encode((string) $image), json_encode($image));
        self::assertSame([], $transport->requests);
    }

    public function testOnlyReferencedAttachedImagesAreSent(): void
    {
        $first = Image::fromBytes(TestImages::PNG);
        $second = Image::fromBytes(TestImages::JPEG);
        [$transport, $client] = $this->client(2);

        $client->attachAssets([$first, $second], function () use ($client, $first): void {
            $client->systemOne(state: "Look at {$first}", questions: [self::QUESTIONS_KEY => new Noul('?')]);
            $client->systemOne(state: 'No images here.', questions: [self::QUESTIONS_KEY => new Noul('?')]);
        });

        self::assertSame([(string) $first], array_keys($this->multipart($transport, 0)['assets']));
        self::assertSame('application/json', $transport->requests[1]['headers']['Content-Type']);
    }

    public function testAttachmentsNestAndEndWithTheirBlock(): void
    {
        $outer = Image::fromBytes(TestImages::PNG);
        $inner = Image::fromBytes(TestImages::JPEG);
        [$transport, $client] = $this->client(3);
        $send = fn () => $client->systemOne(state: "{$outer} {$inner}", questions: [self::QUESTIONS_KEY => new Noul('?')]);

        $result = $client->attachAssets($outer, function () use ($client, $inner, $send): string {
            $client->attachAssets($inner, $send);
            $send();

            return 'done';
        });
        $send();

        self::assertSame('done', $result);
        self::assertSame([(string) $outer, (string) $inner], array_keys($this->multipart($transport, 0)['assets']));
        self::assertSame([(string) $outer], array_keys($this->multipart($transport, 1)['assets']));
        self::assertSame('application/json', $transport->requests[2]['headers']['Content-Type']);
    }

    public function testOverlappingAttachmentsHoldTheImageUntilTheLastEnds(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $client] = $this->client(2);

        $client->attachAssets($image, function () use ($client, $image): void {
            $client->attachAssets([$image], fn () => null);
            // The inner attachment is gone, the outer one still holds the image.
            $client->systemOne(state: "{$image}", questions: [self::QUESTIONS_KEY => new Noul('?')]);
        });
        $client->systemOne(state: "{$image}", questions: [self::QUESTIONS_KEY => new Noul('?')]);

        self::assertSame([(string) $image], array_keys($this->multipart($transport, 0)['assets']));
        self::assertSame('application/json', $transport->requests[1]['headers']['Content-Type']);
    }

    public function testImagesAreDetachedWhenTheCallbackThrows(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        $transport = new FakeTransport(new \RuntimeException('boom'), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport, compression: false);
        $send = fn () => $client->systemOne(state: "{$image}", questions: [self::QUESTIONS_KEY => new Noul('?')]);

        try {
            $client->attachAssets($image, $send);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $error) {
            self::assertSame('boom', $error->getMessage());
        }
        $send();

        self::assertStringStartsWith('multipart/form-data', $transport->requests[0]['headers']['Content-Type']);
        self::assertSame('application/json', $transport->requests[1]['headers']['Content-Type']);
    }

    public function testAttachmentsAreScopedToTheClient(): void
    {
        $image = Image::fromBytes(TestImages::PNG);
        [$transport, $other] = $this->client();
        $attaching = new Client(apiKey: 'test', transport: new FakeTransport(), compression: false);

        $attaching->attachAssets($image, fn () => $other->systemOne(state: "{$image}", questions: [self::QUESTIONS_KEY => new Noul('?')]));

        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
    }

    public function testAttachingANonImageThrows(): void
    {
        $client = new Client(apiKey: 'test', transport: new FakeTransport(), compression: false);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Attached value of type string must be an asset.');
        $client->attachAssets([Image::fromBytes(TestImages::PNG), 'image'], static fn () => self::fail('The callback must not run.'));
    }

    /**
     * @return array{FakeTransport, Client}
     */
    private function client(int $responses = 1): array
    {
        $transport = new FakeTransport(...array_fill(0, $responses, Responses::systemOne()));

        return [$transport, new Client(apiKey: 'test', transport: $transport, compression: false)];
    }

    /**
     * @return list<array{name: string, headers: string, content: string}>
     */
    private function parts(FakeTransport $transport, int $index = 0): array
    {
        $request = $transport->requests[$index];
        self::assertStringStartsWith('multipart/form-data; boundary=', $request['headers']['Content-Type']);

        return MultipartParser::parse($request['headers']['Content-Type'], (string) $request['body']);
    }

    /**
     * @return array{json: array<string, mixed>, assets: array<string, array{contentType: string, content: string}>}
     *     The JSON request part and each asset's content by alias, looked up through the part it names.
     */
    private function multipart(FakeTransport $transport, int $index = 0): array
    {
        $parts = $this->parts($transport, $index);
        $byName = array_column($parts, null, 'name');
        self::assertCount(count($parts), $byName, 'Part names must be unique.');
        $json = Json::object($byName['request']['content']);
        $entries = $json['assets'];
        self::assertIsArray($entries);
        $assets = [];
        foreach ($entries as $alias => $entry) {
            self::assertIsArray($entry);
            self::assertSame('image', $entry['type']);
            $name = $entry['part'];
            self::assertIsString($name);
            $part = $byName[$name];
            self::assertStringStartsWith(sprintf("Content-Disposition: form-data; name=\"%1\$s\"; filename=\"%1\$s\"\r\n", $name), $part['headers']);
            self::assertSame(1, preg_match('/\r\nContent-Type: (.+)$/', $part['headers'], $type));
            $assets[(string) $alias] = ['contentType' => $type[1] ?? '', 'content' => $part['content']];
        }
        // Every part but `request` is claimed by exactly one asset.
        self::assertCount(count($assets) + 1, $byName);

        return ['json' => $json, 'assets' => $assets];
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
