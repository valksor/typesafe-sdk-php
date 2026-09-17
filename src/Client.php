<?php

declare(strict_types=1);

namespace TypeSafe;

final class Client
{
    public const VERSION = '0.6.0';
    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai';
    public const DEFAULT_MODEL = 'jev-latest';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly string $defaultModel;
    private readonly RetryPolicy $retry;
    private readonly float $timeout;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly Transport $transport;

    public readonly Models $models;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?RetryPolicy $retry = null,
        ?float $timeout = null,
        array $headers = [],
        ?Transport $transport = null,
        ?string $baseUrl = null,
    ) {
        $this->apiKey = self::firstNonBlank($apiKey, getenv('TYPESAFE_API_KEY') ?: null)
            ?? throw new TypeSafeException('No API key was provided. Pass apiKey or set TYPESAFE_API_KEY.');
        $resolvedBaseUrl = self::firstNonBlank($baseUrl, getenv('TYPESAFE_BASE_URL') ?: null, self::DEFAULT_BASE_URL)
            ?? throw new TypeSafeException('No base URL could be resolved.');
        $this->baseUrl = rtrim($resolvedBaseUrl, '/');
        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new TypeSafeException(sprintf('Invalid TypeSafe base URL "%s".', $this->baseUrl));
        }
        $this->defaultModel = self::firstNonBlank($model, getenv('TYPESAFE_DEFAULT_MODEL') ?: null, self::DEFAULT_MODEL)
            ?? throw new TypeSafeException('No default model could be resolved.');
        $this->retry = $retry ?? new RetryPolicy();
        $this->timeout = $timeout ?? 10.0;
        if (!is_finite($this->timeout) || $this->timeout <= 0) {
            throw new TypeSafeException('timeout must be a positive finite number of seconds.');
        }
        $this->headers = $headers;
        $this->transport = $transport ?? new CurlTransport();
        $this->models = new Models($this);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function defaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * @param array<array-key, mixed> $questions
     * @param array<string, mixed> $extraBody
     */
    public function systemOne(
        mixed $state,
        array $questions,
        ?string $model = null,
        ?RequestOptions $options = null,
        array $extraBody = [],
    ): SystemOneResponse {
        $normalizedQuestions = $this->validateQuestions($questions);
        $body = $extraBody;
        $body['state'] = $state;
        $body['questions'] = $normalizedQuestions;
        $body['model'] = self::firstNonBlank($model, $this->defaultModel)
            ?? throw new TypeSafeException('No model could be resolved.');
        $response = $this->request('POST', '/v1/systemone', $body, $options);

        try {
            $data = self::decodeObject($response->body);
            $responseModel = self::requiredString($data, 'model');
            $rawAnswers = self::requiredObject($data, 'answers');
            $rawUsage = self::requiredObject($data, 'usage');
            $answers = [];
            foreach ($rawAnswers as $name => $rawAnswer) {
                if (!is_array($rawAnswer)) {
                    throw new \UnexpectedValueException('answers must be an object of answer objects');
                }
                $answers[$name] = $this->decodeAnswer(self::stringMap($rawAnswer, sprintf('answer %s', $name)));
            }
            $usage = new Usage(
                self::optionalInt($rawUsage, 'input_tokens'),
                self::optionalInt($rawUsage, 'output_tokens'),
            );
        } catch (\JsonException | \UnexpectedValueException | \TypeError $error) {
            throw new ResponseValidationError('Invalid System One response: ' . $error->getMessage(), $response, $error);
        }

        return new SystemOneResponse($responseModel, $answers, $usage, $response->meta());
    }

    public function listModels(?RequestOptions $options = null): ModelsResponse
    {
        $response = $this->request('GET', '/v1/models', null, $options);
        try {
            $data = self::decodeObject($response->body);
            $rawModels = self::requiredList($data, 'models');
            $models = [];
            foreach ($rawModels as $rawModel) {
                if (!is_array($rawModel)) {
                    throw new \UnexpectedValueException('models must contain objects');
                }
                $modelData = self::stringMap($rawModel, 'model');
                $models[] = new Model(
                    self::requiredString($modelData, 'name'),
                    self::requiredString($modelData, 'description'),
                    self::requiredString($modelData, 'release_date'),
                );
            }
        } catch (\JsonException | \UnexpectedValueException | \TypeError $error) {
            throw new ResponseValidationError('Invalid models response: ' . $error->getMessage(), $response, $error);
        }
        return new ModelsResponse($models, $response->meta());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body, ?RequestOptions $options): HttpResponse
    {
        $options ??= new RequestOptions();
        $policy = $options->retry ?? $this->retry;
        $timeout = $options->timeout ?? $this->timeout;
        $headers = self::mergeHeaders($this->headers, $options->headers);
        $headers = self::mergeHeaders($headers, [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'typesafe-sdk/' . self::VERSION,
            'X-TypeSafe-SDK' => 'typesafe-sdk/' . self::VERSION,
            'X-TypeSafe-Runtime' => sprintf('php/%s %s', PHP_VERSION, PHP_OS_FAMILY),
        ]);
        self::removeHeader($headers, 'X-TypeSafe-Retry-Count');
        $encodedBody = null;
        if ($body !== null) {
            try {
                $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new TypeSafeException('The request body could not be encoded as JSON.', 0, $error);
            }
            $headers = self::mergeHeaders($headers, ['Content-Type' => 'application/json']);
        }

        for ($attempt = 0; ; ++$attempt) {
            $attemptHeaders = $headers;
            if ($attempt > 0) {
                $attemptHeaders = self::mergeHeaders($attemptHeaders, ['X-TypeSafe-Retry-Count' => (string) $attempt]);
            }
            try {
                $response = $this->transport->send($method, $this->baseUrl . $path, $attemptHeaders, $encodedBody, $timeout);
                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    return $response;
                }
                $error = $this->apiError($response);
            } catch (TransportException $transportError) {
                $error = $transportError->timedOut
                    ? new APITimeoutError($timeout, $transportError)
                    : new APIConnectionError('Connection error: ' . $transportError->getMessage(), 0, $transportError);
                $response = null;
            }

            if ($attempt >= $policy->maxRetries || !$this->retryable($error, $policy)) {
                throw $error;
            }
            $delay = $this->retryDelay($attempt, $response, $policy);
            if ($delay > 0) {
                usleep((int) round($delay * 1_000_000));
            }
        }
    }

    private function apiError(HttpResponse $response): ApiError
    {
        $body = self::decodeAny($response->body);
        $arguments = [$response->statusCode, $body, $response->headers, $response->header('x-typesafe-request-id')];
        $error = match ($response->statusCode) {
            400 => new BadRequestError(...$arguments),
            401 => new AuthenticationError(...$arguments),
            403 => new PermissionDeniedError(...$arguments),
            404 => new NotFoundError(...$arguments),
            409 => new ConflictError(...$arguments),
            422 => new UnprocessableEntityError(...$arguments),
            429 => new RateLimitError(...$arguments),
            default => $response->statusCode >= 500
                ? new InternalServerError(...$arguments)
                : new ApiError(...$arguments),
        };
        if ($error instanceof RateLimitError) {
            $error->retryAfterMilliseconds = $this->retryAfter($response);
        }
        return $error;
    }

    private function retryable(TypeSafeException $error, RetryPolicy $policy): bool
    {
        if ($error instanceof APITimeoutError) {
            return $policy->apiTimeoutError;
        }
        if ($error instanceof APIConnectionError) {
            return $policy->apiConnectionError;
        }
        return $error instanceof ApiError && isset($policy->statuses()[$error->statusCode]);
    }

    private function retryDelay(int $attempt, ?HttpResponse $response, RetryPolicy $policy): float
    {
        if ($policy->respectRetryAfter && $response !== null) {
            $serverDelay = $this->retryAfter($response);
            if ($serverDelay !== null && $serverDelay / 1000 <= $policy->maxRetryAfter) {
                return $serverDelay / 1000;
            }
        }
        $exponential = min($policy->backoffInitial * (2 ** $attempt), $policy->backoffMax);
        $random = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
        return $exponential * (1 - $random * $policy->backoffJitter);
    }

    private function retryAfter(HttpResponse $response): ?float
    {
        $milliseconds = $response->header('retry-after-ms');
        if ($milliseconds !== null && is_numeric(trim($milliseconds))) {
            $value = (float) trim($milliseconds);
            return is_finite($value) && $value >= 0 ? $value : null;
        }
        $raw = $response->header('retry-after');
        if ($raw === null) {
            return null;
        }
        if (is_numeric(trim($raw))) {
            $seconds = (float) trim($raw);
            return is_finite($seconds) && $seconds >= 0 ? $seconds * 1000 : null;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? null : max(0, ($timestamp - time()) * 1000);
    }

    /**
     * @param array<array-key, mixed> $questions
     * @return array<string, Question|array<string, mixed>>
     */
    private function validateQuestions(array $questions): array
    {
        if ($questions === []) {
            throw new TypeSafeException('At least one question is required.');
        }
        $normalized = [];
        foreach ($questions as $name => $question) {
            if (!is_string($name)) {
                throw new TypeSafeException('Question names must be strings.');
            }
            if ($question instanceof Question) {
                $question->validate($name);
                $normalized[$name] = $question;
                continue;
            }
            if (!is_array($question) || !is_string($question['type'] ?? null) || $question['type'] === '') {
                throw new TypeSafeException(sprintf('Question "%s" must be a Question or an array with a type.', $name));
            }
            if (in_array($question['type'], ['choice', 'score'], true) && !array_key_exists('criteria', $question)) {
                throw new TypeSafeException(sprintf('Question "%s" requires criteria.', $name));
            }
            if ($question['type'] === 'score' && (!is_array($question['criteria']) || count($question['criteria']) < 2)) {
                throw new TypeSafeException(sprintf('Score question "%s" requires at least two criteria.', $name));
            }
            $normalized[$name] = self::stringMap($question, sprintf('question %s', $name));
        }
        return $normalized;
    }

    /** @param array<string, mixed> $data */
    private function decodeAnswer(array $data): Answer
    {
        $type = self::requiredString($data, 'type');
        return match ($type) {
            'noul' => new NoulAnswer(self::requiredFloat($data, 'noul')),
            'choice' => new ChoiceAnswer(
                self::requiredString($data, 'choice'),
                self::requiredFloat($data, 'confidence'),
                self::floatMap(self::requiredMap($data, 'probabilities')),
            ),
            'score' => new ScoreAnswer(
                self::requiredFloat($data, 'score'),
                self::requiredFloat($data, 'confidence'),
                self::integerKeyMap(self::requiredMap($data, 'legend')),
                self::integerFloatMap(self::requiredMap($data, 'probabilities')),
            ),
            default => new UnknownAnswer($type, $data),
        };
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $body): array
    {
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('response body must be a JSON object');
        }
        return self::stringMap($data, 'response body');
    }

    private static function decodeAny(string $body): mixed
    {
        if ($body === '') {
            return null;
        }
        try {
            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new \UnexpectedValueException(sprintf('missing or invalid %s', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredFloat(array $data, string $key): float
    {
        if (!is_int($data[$key] ?? null) && !is_float($data[$key] ?? null)) {
            throw new \UnexpectedValueException(sprintf('missing or invalid %s', $key));
        }
        return (float) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function requiredObject(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null)) {
            throw new \UnexpectedValueException(sprintf('missing or invalid %s', $key));
        }
        return self::stringMap($data[$key], $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function requiredMap(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null)) {
            throw new \UnexpectedValueException(sprintf('missing or invalid %s', $key));
        }
        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private static function requiredList(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null) || !array_is_list($data[$key])) {
            throw new \UnexpectedValueException(sprintf('missing or invalid %s', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function optionalInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key])) {
            throw new \UnexpectedValueException(sprintf('invalid %s', $key));
        }
        return $data[$key];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, float>
     */
    private static function floatMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \UnexpectedValueException('probabilities must be numeric');
            }
            $result[(string) $key] = (float) $value;
        }
        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<int, mixed>
     */
    private static function integerKeyMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (filter_var($key, FILTER_VALIDATE_INT) === false) {
                throw new \UnexpectedValueException('score legend keys must be integers');
            }
            $result[(int) $key] = $value;
        }
        return $result;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<int, float>
     */
    private static function integerFloatMap(array $values): array
    {
        $result = self::integerKeyMap($values);
        foreach ($result as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \UnexpectedValueException('score probabilities must be numeric');
            }
            $result[$key] = (float) $value;
        }
        return $result;
    }

    private static function firstNonBlank(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }

    /**
     * @param array<string, string> ...$sources
     * @return array<string, string>
     */
    private static function mergeHeaders(array ...$sources): array
    {
        $merged = [];
        $names = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                $lower = strtolower($name);
                if (isset($names[$lower])) {
                    unset($merged[$names[$lower]]);
                }
                $merged[$name] = $value;
                $names[$lower] = $name;
            }
        }
        return $merged;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringMap(array $values, string $context): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(sprintf('%s must be a JSON object', $context));
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<string, string> $headers */
    private static function removeHeader(array &$headers, string $name): void
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
    }
}
