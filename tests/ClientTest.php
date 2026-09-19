<?php

declare(strict_types=1);

namespace TypeSafe\Tests;

use PHPUnit\Framework\TestCase;
use TypeSafe\AuthenticationError;
use TypeSafe\Choice;
use TypeSafe\ChoiceAnswer;
use TypeSafe\Client;
use TypeSafe\ConflictError;
use TypeSafe\HttpResponse;
use TypeSafe\Noul;
use TypeSafe\NoulAnswer;
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

    public function testRejectsInvalidQuestionsBeforeTransport(): void
    {
        $transport = new FakeTransport();
        $client = new Client(apiKey: 'test', transport: $transport);

        $this->expectException(TypeSafeException::class);
        $client->systemOne('state', ['score' => new Score('Rate it', ['only one'])]);
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
