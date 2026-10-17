<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TypeSafe\Client;
use TypeSafe\Noul;

#[Group('integration')]
final class IntegrationTest extends TestCase
{
    public function testListsLiveModels(): void
    {
        self::requireLiveTests();

        $response = (new Client())->models->list();
        self::assertNotEmpty($response->models);
    }

    /** A body of 1 KiB or more is compressed by default, so this covers both encodings. */
    #[DataProvider('compressions')]
    public function testLiveSystemOneWithALargeBody(string|false|null $compression): void
    {
        self::requireLiveTests();

        $document = str_repeat('I was charged twice for the same order. Please refund the duplicate charge. ', 30);
        self::assertGreaterThan(1024, strlen($document));
        $response = (new Client(timeout: 120, compression: $compression))->systemOne(
            state: ['document' => $document],
            questions: ['billing' => new Noul('Is this message about billing?')],
        );

        $answer = $response->noul('billing');
        self::assertNotNull($answer);
        self::assertGreaterThan(0.5, $answer->noul);
    }

    /** @return iterable<string, array{string|false|null}> */
    public static function compressions(): iterable
    {
        yield 'default compression' => [null];
        yield 'uncompressed' => [false];
    }

    public static function requireLiveTests(): void
    {
        if (getenv('TYPESAFE_RUN_LIVE_TESTS') !== '1' || getenv('TYPESAFE_API_KEY') === false || trim((string) getenv('TYPESAFE_API_KEY')) === '') {
            self::markTestSkipped('Set TYPESAFE_RUN_LIVE_TESTS=1 and TYPESAFE_API_KEY to run live tests.');
        }
    }
}
