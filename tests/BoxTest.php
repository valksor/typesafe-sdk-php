<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeSafe\Box;
use TypeSafe\BoxAnswer;
use TypeSafe\BoxCoordinates;
use TypeSafe\Client;
use TypeSafe\HttpResponse;
use TypeSafe\ResponseValidationError;
use TypeSafe\SystemOneResponse;
use TypeSafe\UnknownAnswer;

final class BoxTest extends TestCase
{
    private const BOX = ['top' => 0.1, 'bottom' => 0.6, 'left' => 0.25, 'right' => 0.75];

    public function testBoxEncodesWithoutNullInstructions(): void
    {
        self::assertSame('{"type":"box"}', json_encode(new Box()));
        self::assertSame('{"type":"box","instructions":"The dog"}', json_encode(new Box('The dog')));
        self::assertSame('{"type":"box","instructions":{"find":"dog"}}', json_encode(new Box(['find' => 'dog'])));
    }

    public function testBoxQuestionsAreSentTypedOrRaw(): void
    {
        $transport = new FakeTransport(Responses::systemOne(), Responses::systemOne());
        $client = new Client(apiKey: 'test', transport: $transport, compression: false);

        $client->systemOne(state: 'photo', questions: ['dog' => new Box('The dog')]);
        $client->systemOne(state: 'photo', questions: ['dog' => ['type' => 'box']]);

        $first = json_decode((string) $transport->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode((string) $transport->requests[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(['dog' => ['type' => 'box', 'instructions' => 'The dog']], $first['questions']);
        self::assertSame(['dog' => ['type' => 'box']], $second['questions']);
    }

    #[DataProvider('confidences')]
    public function testDecodesBoxAnswer(mixed $confidence, ?float $expected): void
    {
        $response = $this->respond(['dog' => ['type' => 'box', 'box' => self::BOX, 'confidence' => $confidence, 'extra' => ['k' => [1.0]]]]);

        $answer = $response->box('dog');
        self::assertInstanceOf(BoxAnswer::class, $answer);
        self::assertSame($answer, $response->answers['dog']);
        self::assertSame('box', $answer->type());
        self::assertEquals(new BoxCoordinates(0.1, 0.6, 0.25, 0.75), $answer->box);
        self::assertSame($expected, $answer->confidence);
        self::assertNull($response->noul('dog'));
        self::assertNull($response->box('missing'));
    }

    /** @return iterable<string, array{mixed, ?float}> */
    public static function confidences(): iterable
    {
        yield 'null' => [null, null];
        yield 'float' => [0.9, 0.9];
        yield 'int' => [1, 1.0];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('invalidAnswers')]
    public function testInvalidBoxAnswerIsAnInvalidResponse(array $answer): void
    {
        $this->expectException(ResponseValidationError::class);
        $this->respond(['dog' => ['type' => 'box', ...$answer]]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAnswers(): iterable
    {
        yield 'missing box' => [['confidence' => 0.5]];
        yield 'null box' => [['box' => null, 'confidence' => 0.5]];
        yield 'list box' => [['box' => [0.1, 0.6, 0.25, 0.75], 'confidence' => 0.5]];
        yield 'missing confidence' => [['box' => self::BOX]];
        yield 'string confidence' => [['box' => self::BOX, 'confidence' => 'high']];
        foreach (array_keys(self::BOX) as $edge) {
            $box = self::BOX;
            unset($box[$edge]);
            yield "missing {$edge}" => [['box' => $box, 'confidence' => 0.5]];
            yield "null {$edge}" => [['box' => [...$box, $edge => null], 'confidence' => 0.5]];
            yield "string {$edge}" => [['box' => [...$box, $edge => '0.5'], 'confidence' => 0.5]];
        }
    }

    public function testBoxAnswersWorkWithResponseModels(): void
    {
        $transport = new FakeTransport(Responses::systemOne(['dog' => ['type' => 'box', 'box' => self::BOX, 'confidence' => null]]));
        $client = new Client(apiKey: 'test', transport: $transport);

        $located = $client->systemOne(state: 'photo', questions: ['dog' => new Box('The dog')], responseModel: LocatedModel::class);

        self::assertSame(0.6, $located->bottom);
    }

    public function testUnknownAnswerTypesStayUnknown(): void
    {
        self::assertInstanceOf(UnknownAnswer::class, $this->respond(['dog' => ['type' => 'aurora']])->answers['dog']);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function respond(array $answers): SystemOneResponse
    {
        $transport = new FakeTransport(new HttpResponse(200, [], json_encode([
            'model' => 'vision',
            'answers' => $answers,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $response = (new Client(apiKey: 'test', transport: $transport))->systemOne(state: 'photo', questions: ['dog' => new Box()]);
        self::assertInstanceOf(SystemOneResponse::class, $response);

        return $response;
    }
}
