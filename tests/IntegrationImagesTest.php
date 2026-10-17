<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TypeSafe\Box;
use TypeSafe\Choice;
use TypeSafe\Client;
use TypeSafe\Image;
use TypeSafe\Noul;
use TypeSafe\Score;

/**
 * Live image tests, run only when TYPESAFE_LIVE_IMAGES is set in addition to the live-test switch,
 * against an API that accepts images.
 */
#[Group('integration')]
final class IntegrationImagesTest extends TestCase
{
    /** The API rejects images below a minimum size, so test images are comfortably above it. */
    private const SIZE = 512;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** @return iterable<string, array{string|false|null}> */
    public static function compressions(): iterable
    {
        yield 'default compression' => [null];
        yield 'uncompressed' => [false];
    }

    #[DataProvider('compressions')]
    public function testLiveInlinePng(string|false|null $compression): void
    {
        $client = $this->client($compression);

        $response = $client->systemOne(
            state: ['Here is a photo:', Image::fromBytes(TestImages::png(self::SIZE, self::SIZE, 255, 0, 0))],
            questions: ['red' => new Noul('Is the photo a solid red square?')],
        );

        self::assertGreaterThan(0.5, $response->noul('red')?->noul);
    }

    #[DataProvider('compressions')]
    public function testLiveInlineJpeg(string|false|null $compression): void
    {
        $jpeg = TestImages::jpeg(self::SIZE, self::SIZE, 255, 0, 0);
        if ($jpeg === null) {
            self::markTestSkipped('Generating a JPEG needs ext-gd.');
        }

        $response = $this->client($compression)->systemOne(
            state: ['Here is a photo:', Image::fromBytes($jpeg)],
            questions: ['red' => new Noul('Is the photo a solid red square?')],
        );

        self::assertGreaterThan(0.5, $response->noul('red')?->noul);
    }

    #[DataProvider('compressions')]
    public function testLiveImageUnderAliasAndReference(string|false|null $compression): void
    {
        // Sent under two asset keys, so each key needs its own part.
        $photo = Image::fromBytes(TestImages::png(self::SIZE, self::SIZE, 255, 0, 0));

        $response = $this->client($compression)->systemOne(
            state: "Compare <photo> with {$photo}.",
            questions: ['same' => new Noul('Are both photos the same?')],
            assets: ['<photo>' => $photo],
        );

        self::assertGreaterThan(0.5, $response->noul('same')?->noul);
    }

    #[DataProvider('compressions')]
    public function testLiveImageInQuestion(string|false|null $compression): void
    {
        $photo = Image::fromBase64(base64_encode(TestImages::png(self::SIZE, self::SIZE, 255, 0, 0)));

        $response = $this->client($compression)->systemOne(
            state: 'Look at the image in the question.',
            questions: ['red' => new Noul(['Is this image a solid red square?', $photo])],
        );

        self::assertGreaterThan(0.5, $response->noul('red')?->noul);
    }

    #[DataProvider('compressions')]
    public function testLiveImagesInQuestionCriteria(string|false|null $compression): void
    {
        $red = TestImages::png(self::SIZE, self::SIZE, 255, 0, 0);
        $blue = TestImages::png(self::SIZE, self::SIZE, 0, 0, 255);

        $response = $this->client($compression)->systemOne(
            state: Image::fromBytes($red),
            questions: [
                'color' => new Choice(
                    'Which sample has the same color as the state image?',
                    ['red' => Image::fromBytes($red), 'blue' => Image::fromBytes($blue)],
                ),
                'similarity' => new Score(
                    'How similar is the state image to the examples?',
                    [Image::fromBytes($blue), Image::fromBytes($red)],
                ),
            ],
        );

        self::assertSame('red', $response->choice('color')?->choice);
        self::assertGreaterThan(0.5, $response->score('similarity')?->score);
    }

    #[DataProvider('compressions')]
    public function testLiveAttachedImageFile(string|false|null $compression): void
    {
        $path = tempnam(sys_get_temp_dir(), 'typesafe-red');
        self::assertIsString($path);
        $this->files[] = $path;
        file_put_contents($path, TestImages::png(self::SIZE, self::SIZE, 255, 0, 0));
        $photo = Image::fromFile($path);
        $client = $this->client($compression);

        $response = $client->attachAssets($photo, fn () => $client->systemOne(
            state: "Here is a photo: {$photo}",
            questions: ['red' => new Noul('Is the photo a solid red square?')],
        ));

        self::assertGreaterThan(0.5, $response->noul('red')?->noul);
    }

    #[DataProvider('compressions')]
    public function testLiveBox(string|false|null $compression): void
    {
        $response = $this->client($compression)->systemOne(
            state: Image::fromBytes(TestImages::redSquareOnWhite(self::SIZE)),
            questions: ['red_square' => new Box('The red square in the center')],
            // The API rejects box questions on the default model.
            model: 'jev-preview',
        );

        $box = $response->box('red_square');
        self::assertNotNull($box);
        foreach ([$box->box->top, $box->box->bottom, $box->box->left, $box->box->right] as $edge) {
            self::assertGreaterThanOrEqual(0, $edge);
            self::assertLessThanOrEqual(1, $edge);
        }
        if ($box->confidence !== null) {
            self::assertGreaterThanOrEqual(0, $box->confidence);
            self::assertLessThanOrEqual(1, $box->confidence);
        }
    }

    private function client(string|false|null $compression): Client
    {
        IntegrationTest::requireLiveTests();
        if (getenv('TYPESAFE_LIVE_IMAGES') === false || getenv('TYPESAFE_LIVE_IMAGES') === '') {
            self::markTestSkipped('Set TYPESAFE_LIVE_IMAGES=1 to run the live image tests.');
        }

        // The API rejects image requests that don't opt into the experimental feature.
        return new Client(timeout: 120, headers: ['TypeSafe-Danger-Zone-Images' => 'true'], compression: $compression);
    }
}
