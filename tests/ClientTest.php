<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeSafe\AuthenticationError;
use TypeSafe\Choice;
use TypeSafe\ChoiceAnswer;
use TypeSafe\Client;
use TypeSafe\ConflictError;
use TypeSafe\HttpResponse;
use TypeSafe\Noul;
use TypeSafe\NoulAnswer;
use TypeSafe\NoulCriteria;
use TypeSafe\RequestOptions;
use TypeSafe\ResponseValidationError;
use TypeSafe\RetryPolicy;
use TypeSafe\Score;
use TypeSafe\ScoreAnswer;
use TypeSafe\SystemOneResponse;
use TypeSafe\TypeSafeException;

final class ClientTest extends TestCase
{
    public function testSystemOneSerializesQuestionsAndDecodesTypedAnswers(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, [
            'content-type' => ['application/json'],
            'x-typesafe-request-id' => ['req_123'],
        ], json_encode([
            'model' => 'jev-latest',
            'answers' => [
                'urgent' => ['type' => 'noul', 'noul' => 0.9],
                'team' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.8, 'probabilities' => ['billing' => 0.9, 'other' => 0.1]],
                'tone' => ['type' => 'score', 'score' => 1.5, 'confidence' => 0.7, 'legend' => ['0' => 'calm', '1' => 'tense', '2' => 'angry'], 'probabilities' => ['0' => 0.1, '1' => 0.3, '2' => 0.6]],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ], JSON_THROW_ON_ERROR)));
        $client = new Client(apiKey: 'test', transport: $transport);

        $response = $client->systemOne(
            state: ['message' => 'Please help'],
            questions: [
                'urgent' => new Noul('Is this urgent?'),
                'team' => new Choice('Which team?', ['billing' => null, 'other' => null]),
                'tone' => new Score('How tense?', ['calm', 'tense', 'angry']),
            ],
        );

        self::assertInstanceOf(NoulAnswer::class, $response->noul('urgent'));
        self::assertInstanceOf(ChoiceAnswer::class, $response->choice('team'));
        self::assertInstanceOf(ScoreAnswer::class, $response->score('tone'));
        self::assertSame('billing', $response->choice('team')->choice);
        self::assertSame('angry', $response->score('tone')->legend[2]);
        self::assertSame('req_123', $response->meta->requestId);
        self::assertSame(10, $response->usage->inputTokens);

        $request = $transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('Bearer test', $request['headers']['Authorization']);
        $body = json_decode($request['body'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $questions = $body['questions'] ?? null;
        self::assertIsArray($questions);
        $tone = $questions['tone'] ?? null;
        self::assertIsArray($tone);
        self::assertSame('score', $tone['type'] ?? null);
        self::assertSame('jev-latest', $body['model']);
    }

    public function testRetriesEligibleResponseAndSetsRetryCount(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(429, ['retry-after-ms' => ['0']], '{"message":"slow down"}'),
            new HttpResponse(200, [], '{"models":[]}'),
        );
        $client = new Client(apiKey: 'test', transport: $transport);

        $response = $client->models->list();

        self::assertSame([], $response->models);
        self::assertCount(2, $transport->requests);
        self::assertSame('1', $transport->requests[1]['headers']['X-TypeSafe-Retry-Count']);
    }

    public function testMapsStatusErrors(): void
    {
        $transport = new FakeTransport(new HttpResponse(401, ['x-typesafe-request-id' => ['req_bad']], '{"error":"invalid key"}'));
        $client = new Client(apiKey: 'test', retry: new RetryPolicy(maxRetries: 0), transport: $transport);

        try {
            $client->models->list();
            self::fail('Expected AuthenticationError.');
        } catch (AuthenticationError $error) {
            self::assertSame(401, $error->statusCode);
            self::assertSame('req_bad', $error->requestId);
            self::assertSame('401 invalid key (request_id=req_bad)', $error->getMessage());
        }
    }

    public function testMapsConflictError(): void
    {
        $transport = new FakeTransport(new HttpResponse(409, [], '{"message":"conflict"}'));
        $client = new Client(apiKey: 'test', retry: new RetryPolicy(maxRetries: 0), transport: $transport);

        $this->expectException(ConflictError::class);
        $client->models->list();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidApiKeys(): iterable
    {
        yield 'internal space' => ['abc def'];
        yield 'tab' => ["abc\tdef"];
        yield 'newline' => ["abc\ndef"];
        yield 'control char' => ["abc\x01def"];
        yield 'del char' => ["abc\x7fdef"];
        yield 'non-ascii' => ['abcdéf'];
    }

    #[DataProvider('invalidApiKeys')]
    public function testRejectsInvalidApiKey(string $apiKey): void
    {
        $this->expectException(TypeSafeException::class);
        new Client(apiKey: $apiKey, transport: new FakeTransport());
    }

    public function testAcceptsPrintableAsciiApiKey(): void
    {
        // Surrounding whitespace is trimmed during resolution; the punctuation-rich key is valid.
        $client = new Client(apiKey: '  sk-Test_123.ABC-xyz+/=  ', transport: new FakeTransport());
        $this->assertSame('https://api.typesafe.ai', $client->baseUrl());
    }

    /**
     * @return iterable<string, array{mixed, array<array-key, mixed>, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'missing state' => [null, ['q' => new Noul('Q?')], 'State is required.'];
        yield 'empty typed choice' => ['s', ['label' => new Choice('Tone?', [])], 'Choice question "label" has no criteria; at least one choice is required.'];
        yield 'empty stdClass raw choice' => ['s', ['label' => ['type' => 'choice', 'criteria' => new \stdClass()]], 'Choice question "label" has no criteria; at least one choice is required.'];
        yield 'empty raw choice' => ['s', ['label' => ['type' => 'choice', 'criteria' => []]], 'Choice question "label" has no criteria; at least one choice is required.'];
        yield 'null raw choice' => ['s', ['label' => ['type' => 'choice', 'criteria' => null]], 'Choice question "label" has no criteria; at least one choice is required.'];
        yield 'empty typed score' => ['s', ['rating' => new Score('Rate it', [])], 'Score question "rating" has no criteria; at least one score is required.'];
        yield 'empty raw score' => ['s', ['rating' => ['type' => 'score', 'criteria' => []]], 'Score question "rating" has no criteria; at least one score is required.'];
        yield 'null raw score' => ['s', ['rating' => ['type' => 'score', 'criteria' => null]], 'Score question "rating" has no criteria; at least one score is required.'];
        yield 'bare typed noul' => ['s', ['spam' => new Noul()], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'typed noul with null criteria values' => ['s', ['spam' => new Noul(null, new NoulCriteria())], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'bare raw noul' => ['s', ['spam' => ['type' => 'noul']], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'raw noul null instructions, empty criteria' => ['s', ['spam' => ['type' => 'noul', 'instructions' => null, 'criteria' => []]], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'raw noul null instructions, null criteria values' => ['s', ['spam' => ['type' => 'noul', 'criteria' => ['true' => null, 'false' => null]]], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'raw noul null instructions, null NoulCriteria values' => ['s', ['spam' => ['type' => 'noul', 'criteria' => new NoulCriteria()]], 'Noul question "spam" has neither instructions nor criteria; at least one is required.'];
        yield 'raw noul scalar criteria' => ['s', ['spam' => ['type' => 'noul', 'instructions' => 'Spam?', 'criteria' => 'spam']], 'Noul question "spam" criteria must be an object.'];
        yield 'raw noul list criteria' => ['s', ['spam' => ['type' => 'noul', 'instructions' => 'Spam?', 'criteria' => ['spam']]], 'Noul question "spam" criteria must be an object.'];
    }

    /**
     * @param array<array-key, mixed> $questions
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequestsBeforeTransport(mixed $state, array $questions, string $message): void
    {
        $transport = new FakeTransport();
        $client = new Client(apiKey: 'test', transport: $transport);

        try {
            $client->systemOne($state, $questions);
            self::fail('Expected TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertSame([], $transport->requests);
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function validQuestions(): iterable
    {
        yield 'single-criterion typed score' => [['rating' => new Score('Rate it', ['only one'])]];
        yield 'single-criterion raw score' => [['rating' => ['type' => 'score', 'criteria' => ['only one']]]];
        yield 'raw choice with stdClass criteria' => [['label' => ['type' => 'choice', 'criteria' => (object) ['a' => null]]]];
        yield 'noul with instructions only' => [['spam' => new Noul('Spam?')]];
        yield 'noul with criteria only' => [['spam' => new Noul(null, new NoulCriteria(true: 'spam'))]];
        yield 'raw noul with criteria map only' => [['spam' => ['type' => 'noul', 'criteria' => ['false' => 'ham']]]];
        yield 'raw noul with NoulCriteria only' => [['spam' => ['type' => 'noul', 'criteria' => new NoulCriteria(false: ['ham'])]]];
        yield 'raw noul with null criteria' => [['spam' => ['type' => 'noul', 'instructions' => 'Spam?', 'criteria' => null]]];
        yield 'raw noul with empty criteria and instructions' => [['spam' => ['type' => 'noul', 'instructions' => '', 'criteria' => []]]];
    }

    /**
     * @param array<array-key, mixed> $questions
     */
    #[DataProvider('validQuestions')]
    public function testAcceptsValidQuestions(array $questions): void
    {
        $transport = new FakeTransport(new HttpResponse(200, [], '{"model":"jev-latest","answers":{},"usage":{}}'));
        $client = new Client(apiKey: 'test', transport: $transport);

        $client->systemOne('state', $questions);

        self::assertCount(1, $transport->requests);
    }

    public function testNonFiniteFloatsAreSerializedAsStrings(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, [], '{"model":"jev-latest","answers":{},"usage":{}}'));
        $client = new Client(apiKey: 'test', transport: $transport);

        $client->systemOne(
            state: ['obj' => (object) ['x' => NAN, 'list' => [INF]], 'empty' => new \stdClass(), 'value' => NAN, 'arr' => [1.5, INF, -INF], 'nested' => ['deep' => ['x' => NAN]], 'finite' => 1.5],
            questions: [
                'typed' => new Noul(['weight' => INF], new NoulCriteria(true: ['p' => -INF], false: NAN)),
                'choice' => new Choice('Which?', ['a' => [NAN], 'b' => null]),
                'score' => new Score('How much?', [INF, 2.5]),
                'raw' => ['type' => 'noul', 'instructions' => NAN],
            ],
            extraBody: ['temperature' => NAN],
        );

        $body = $transport->requests[0]['body'] ?? '';
        self::assertStringNotContainsString('INF', $body);
        self::assertStringNotContainsString('NAN', $body);
        // A stdClass state still encodes as a JSON object, including when empty.
        self::assertStringContainsString('"obj":{"x":"NaN","list":["Infinity"]},"empty":{}', $body);
        self::assertSame([
            'temperature' => 'NaN',
            'state' => ['obj' => ['x' => 'NaN', 'list' => ['Infinity']], 'empty' => [], 'value' => 'NaN', 'arr' => [1.5, 'Infinity', '-Infinity'], 'nested' => ['deep' => ['x' => 'NaN']], 'finite' => 1.5],
            'questions' => [
                'typed' => ['type' => 'noul', 'instructions' => ['weight' => 'Infinity'], 'criteria' => ['true' => ['p' => '-Infinity'], 'false' => 'NaN']],
                'choice' => ['type' => 'choice', 'instructions' => 'Which?', 'criteria' => ['a' => ['NaN'], 'b' => null]],
                'score' => ['type' => 'score', 'instructions' => 'How much?', 'criteria' => ['Infinity', 2.5]],
                'raw' => ['type' => 'noul', 'instructions' => 'NaN'],
            ],
            'model' => 'jev-latest',
        ], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFiniteBodyEncodesExactlyAsBefore(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, [], '{"model":"jev-latest","answers":{},"usage":{}}'));
        $client = new Client(apiKey: 'test', transport: $transport);

        $client->systemOne(['a' => 1, 'b' => 0.1, 'c' => [], 'd' => 'é/'], ['q' => new Choice('Q?', ['x' => null])]);

        self::assertSame(
            '{"state":{"a":1,"b":0.1,"c":[],"d":"\u00e9\/"},"questions":{"q":{"type":"choice","instructions":"Q?","criteria":{"x":null}}},"model":"jev-latest"}',
            $transport->requests[0]['body'],
        );
    }

    public function testMalformedSuccessRaisesValidationError(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, [], '{"model":"jev-latest"}'));
        $client = new Client(apiKey: 'test', transport: $transport);

        $this->expectException(ResponseValidationError::class);
        $client->systemOne('state', ['ok' => new Noul('Is it okay?')], options: new RequestOptions());
    }

    /**
     * @param array<string, mixed> $extraAnswers
     */
    private static function triageTransport(array $extraAnswers = []): FakeTransport
    {
        return new FakeTransport(new HttpResponse(200, [
            'x-typesafe-request-id' => ['req_model'],
        ], json_encode([
            'model' => 'jev-latest',
            'answers' => [
                'spam' => ['type' => 'noul', 'noul' => 0.98],
                'team' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.8, 'probabilities' => ['billing' => 0.9, 'other' => 0.1]],
                'tone' => ['type' => 'score', 'score' => 1.7, 'confidence' => 0.7, 'legend' => ['0' => 'calm', '1' => 'tense'], 'probabilities' => ['0' => 0.3, '1' => 0.7]],
                ...$extraAnswers,
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, Noul|Choice|Score>
     */
    private static function triageQuestions(): array
    {
        return [
            'spam' => new Noul('Is this spam?'),
            'team' => new Choice('Which team?', ['billing' => null, 'other' => null]),
            'tone' => new Score('How tense?', ['calm', 'tense']),
        ];
    }

    public function testResponseModelReturnsTypedModel(): void
    {
        $client = new Client(apiKey: 'test', transport: self::triageTransport());

        $triage = $client->systemOne('state', self::triageQuestions(), responseModel: TicketTriageModel::class);

        self::assertInstanceOf(TicketTriageModel::class, $triage);
        self::assertSame(0.98, $triage->spam);
        self::assertSame('billing', $triage->team);
        self::assertSame(1.7, $triage->tone);
        self::assertSame('jev-latest', $triage->model);
        self::assertSame(10, $triage->usage->inputTokens);
    }

    public function testResponseModelIgnoresUnknownAnswerType(): void
    {
        $transport = self::triageTransport(['sentiment' => ['type' => 'vibes', 'value' => 1]]);
        $client = new Client(apiKey: 'test', transport: $transport);

        $triage = $client->systemOne('state', self::triageQuestions(), responseModel: TicketTriageModel::class);

        self::assertInstanceOf(TicketTriageModel::class, $triage);
        self::assertSame(0.98, $triage->spam);
        self::assertSame('billing', $triage->team);
        self::assertSame(1.7, $triage->tone);
    }

    public function testResponseModelValidationFailureRaisesValidationError(): void
    {
        // Omit "team" so the factory throws \UnexpectedValueException.
        $transport = new FakeTransport(new HttpResponse(200, [
            'x-typesafe-request-id' => ['req_invalid'],
        ], json_encode([
            'model' => 'jev-latest',
            'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.98]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
        $client = new Client(apiKey: 'test', transport: $transport);

        try {
            $client->systemOne('state', self::triageQuestions(), responseModel: TicketTriageModel::class);
            self::fail('Expected ResponseValidationError.');
        } catch (ResponseValidationError $error) {
            self::assertInstanceOf(\UnexpectedValueException::class, $error->getPrevious());
            self::assertStringContainsString(TicketTriageModel::class, $error->getMessage());
            self::assertSame('req_invalid', $error->response->meta()->requestId);
        }
    }

    public function testResponseModelGuardRejectsNonImplementingClass(): void
    {
        $client = new Client(apiKey: 'test', transport: self::triageTransport());

        $this->expectException(TypeSafeException::class);
        // Intentionally passing a non-ResponseModel class to exercise the runtime guard.
        // @phpstan-ignore argument.templateType, argument.type
        $client->systemOne('state', self::triageQuestions(), responseModel: \stdClass::class);
    }

    public function testResponseModelGuardRejectsUnknownClassString(): void
    {
        $client = new Client(apiKey: 'test', transport: self::triageTransport());

        $this->expectException(TypeSafeException::class);
        // A non-existent class-string also fails the guard (autoload finds nothing).
        // @phpstan-ignore argument.templateType, argument.type
        $client->systemOne('state', self::triageQuestions(), responseModel: '\\TypeSafe\\Tests\\NoSuchResponseModel');
    }

    public function testResponseModelUncaughtExceptionPropagatesRaw(): void
    {
        $client = new Client(apiKey: 'test', transport: self::triageTransport());

        // ThrowingModel throws \LogicException, which is not rewrapped as a validation error.
        $this->expectException(\LogicException::class);
        $client->systemOne('state', self::triageQuestions(), responseModel: ThrowingModel::class);
    }

    public function testResponseModelStillRaisesApiError(): void
    {
        $transport = new FakeTransport(new HttpResponse(401, ['x-typesafe-request-id' => ['req_bad']], '{"error":"invalid key"}'));
        $client = new Client(apiKey: 'test', retry: new RetryPolicy(maxRetries: 0), transport: $transport);

        $this->expectException(AuthenticationError::class);
        $client->systemOne('state', self::triageQuestions(), responseModel: TicketTriageModel::class);
    }

    public function testOmittingResponseModelReturnsSystemOneResponse(): void
    {
        $client = new Client(apiKey: 'test', transport: self::triageTransport());

        $response = $client->systemOne('state', self::triageQuestions());

        self::assertInstanceOf(SystemOneResponse::class, $response);
        self::assertSame(0.98, $response->noul('spam')?->noul);
    }
}
