<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TypeSafe\Client;

#[Group('integration')]
final class IntegrationTest extends TestCase
{
    public function testListsLiveModels(): void
    {
        if (getenv('TYPESAFE_RUN_LIVE_TESTS') !== '1' || getenv('TYPESAFE_API_KEY') === false || trim((string) getenv('TYPESAFE_API_KEY')) === '') {
            self::markTestSkipped('Set TYPESAFE_RUN_LIVE_TESTS=1 and TYPESAFE_API_KEY to run live tests.');
        }

        $response = (new Client())->models->list();
        self::assertNotEmpty($response->models);
    }
}
