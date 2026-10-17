<?php

declare(strict_types=1);

namespace TypeSafe;

final class Client
{
    public const VERSION = '0.7.5';
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
    private readonly ?string $compression;

    /** @var array<string, Image> Images attached with attachAssets(), by reference. */
    private array $attached = [];
    /** @var array<string, int> How many active attachAssets() calls hold each attached image. */
    private array $holds = [];

    public readonly Models $models;

    /**
     * @param array<string, string> $headers
     * @param string|false|null $compression 'zstd' to compress request bodies of 1 KiB or more (requires
     *     ext-zstd), false to send them uncompressed, or null (default) for zstd when ext-zstd is loaded
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?RetryPolicy $retry = null,
        ?float $timeout = null,
        array $headers = [],
        ?Transport $transport = null,
        ?string $baseUrl = null,
        string|false|null $compression = null,
    ) {
        $this->apiKey = self::firstNonBlank($apiKey, getenv('TYPESAFE_API_KEY') ?: null)
            ?? throw new TypeSafeException('No API key was provided. Pass apiKey or set TYPESAFE_API_KEY.');
        // A trimmed key must be printable ASCII with no whitespace (\x21-\x7e is '!' through '~',
        // which excludes the space at \x20, control characters, and every non-ASCII byte). This
        // keeps a malformed credential out of the Authorization header, where whitespace or
        // control bytes could otherwise inject or split request headers.
        if (preg_match('/[^\x21-\x7e]/', $this->apiKey) === 1) {
            throw new TypeSafeException('API key must contain only printable ASCII characters without whitespace.');
        }
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
        $this->compression = Compression::resolve($compression);
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
     * @template T of ResponseModel
     *
     * @param array<array-key, mixed> $questions
     * @param array<string, mixed> $extraBody
     * @param array<array-key, mixed>|null $assets {@see Image} objects by alias. The API replaces an alias
     *     wherever it appears as or inside a string, so aliases must be unique placeholders such as
     *     "<photo>" or a UUID, never plain words
     * @param class-string<T>|null $responseModel a class implementing {@see ResponseModel} to
     *     decode the response into; must be a trusted, developer-literal class name, never
     *     derived from request or user input
     *
     * @return ($responseModel is null ? SystemOneResponse : T)
     */
    public function systemOne(
        mixed $state,
        array $questions,
        ?string $model = null,
        ?RequestOptions $options = null,
        array $extraBody = [],
        ?string $responseModel = null,
        ?array $assets = null,
    ): SystemOneResponse|ResponseModel {
        if ($state === null) {
            throw new TypeSafeException('State is required.');
        }
        $normalizedQuestions = $this->validateQuestions($questions);
        // Validate the response-model class-string before spending the network call. This is a
        // runtime guard for untyped or dynamic callers: PHPStan proves it from the
        // class-string<T of ResponseModel> type, but a caller not running PHPStan can still pass a
        // bad class-string, which would otherwise become a raw \Error at the factory call below.
        // @phpstan-ignore function.alreadyNarrowedType
        if ($responseModel !== null && !is_a($responseModel, ResponseModel::class, true)) {
            throw new TypeSafeException(sprintf('%s is not a class implementing %s.', $responseModel, ResponseModel::class));
        }
        $body = $extraBody;
        $body['state'] = $state;
        $body['questions'] = $normalizedQuestions;
        $body['model'] = self::firstNonBlank($model, $this->defaultModel)
            ?? throw new TypeSafeException('No model could be resolved.');
        [$encodedBody, $bodyHeaders] = $this->encodeSystemOne($body, $assets, array_key_exists('assets', $extraBody));
        $response = $this->request('POST', '/v1/systemone', $encodedBody, $bodyHeaders, $options);

        try {
            $data = self::decodeObject($response->body);
            $decodedModel = self::requiredString($data, 'model');
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

        $built = new SystemOneResponse($decodedModel, $answers, $usage, $response->meta());
        if ($responseModel === null) {
            return $built;
        }
        try {
            return $responseModel::fromSystemOne($built);
        } catch (\UnexpectedValueException $error) {
            // Only the documented validation signal is rewrapped; every other exception type
            // (a genuine bug in the model class) propagates unchanged.
            throw new ResponseValidationError(
                sprintf('Invalid System One response for %s: %s', $responseModel, $error->getMessage()),
                $response,
                $error,
            );
        }
    }

    /**
     * Attaches images for the duration of $fn, so any text sent meanwhile may contain their references
     * (cast the image to a string). Only attached images whose reference appears in the request are
     * sent. Attachments are client-wide, shared by every call made meanwhile: pass `assets` per call
     * instead when one client serves several tenants. Overlapping attachments of an image keep it
     * attached until the last one ends.
     *
     * @template T
     *
     * @param Image|array<array-key, mixed> $assets
     * @param callable(): T $fn
     *
     * @return T
     *
     * @throws TypeSafeException An attached value is not an Image.
     */
    public function attachAssets(Image|array $assets, callable $fn): mixed
    {
        $images = $assets instanceof Image ? [$assets] : array_values($assets);
        foreach ($images as $image) {
            if (!$image instanceof Image) {
                throw new TypeSafeException(sprintf('Attached value of type %s must be an asset.', get_debug_type($image)));
            }
        }
        foreach ($images as $image) {
            $reference = $image->reference();
            $this->attached[$reference] = $image;
            $this->holds[$reference] = ($this->holds[$reference] ?? 0) + 1;
        }
        try {
            return $fn();
        } finally {
            foreach ($images as $image) {
                $reference = $image->reference();
                if (--$this->holds[$reference] === 0) {
                    unset($this->holds[$reference], $this->attached[$reference]);
                }
            }
        }
    }

    public function listModels(?RequestOptions $options = null): ModelsResponse
    {
        $response = $this->request('GET', '/v1/models', null, [], $options);
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
     * Encodes a System One request body. Images placed in the body encode as their alias (when passed in
     * `assets`) or their reference. Images that are passed, attached, or placed in the body are known by
     * reference: the ones whose reference appears in the encoded JSON are sent too. With nothing to send
     * the body is plain JSON, otherwise `multipart/form-data` with the JSON as the `request` part
     * followed by one `asset-N` part per asset name. An `assets` key in `extraBody` replaces all of it.
     *
     * @param array<string, mixed> $body
     * @param array<array-key, mixed>|null $assets
     *
     * @return array{string, array<string, string>} The encoded body and its headers.
     */
    private function encodeSystemOne(array $body, ?array $assets, bool $overridden): array
    {
        $passed = [];
        $aliases = [];
        foreach ($assets ?? [] as $key => $asset) {
            $name = (string) $key;
            if ($asset instanceof Image) {
                $aliases[spl_object_id($asset)] = $name;
            }
            if ($overridden) {
                continue;
            }
            if (trim($name) === '') {
                throw new TypeSafeException('Asset aliases must be non-empty strings.');
            }
            if (!$asset instanceof Image) {
                throw new TypeSafeException(sprintf('Asset "%s" must be an asset.', $name));
            }
            $passed[] = [$name, $asset];
        }

        // The encoding context spans the whole encode path, as jsonSerialize() runs in both steps.
        return Image::collecting($aliases, function (ImageReferences $references) use ($body, $passed, $overridden): array {
            $prepared = array_map(self::stringifyNonFinite(...), $body);
            $json = self::encodeJson($prepared);
            $jsonHeaders = ['Content-Type' => 'application/json'];
            if ($overridden) {
                return [$json, $jsonHeaders];
            }

            $known = $this->attached;
            foreach ($passed as [, $image]) {
                $known[$image->reference()] = $image;
            }
            $known += $references->found;
            $pending = $passed;
            $names = [];
            foreach ($passed as [$name]) {
                $names[$name] = true;
            }
            // References are JSON-safe ASCII, so one scan of the encoded body finds them in every string.
            if ($known !== [] && preg_match_all('/<image:[0-9a-f]{32}>/', $json, $matches) > 0) {
                foreach ($matches[0] as $reference) {
                    if (isset($known[$reference]) && !isset($names[$reference])) {
                        $names[$reference] = true;
                        $pending[] = [$reference, $known[$reference]];
                    }
                }
            }
            if ($pending === []) {
                // The API rejects an empty `assets`, so it's left out.
                return [$json, $jsonHeaders];
            }

            // An image under several names is read once, but sent once per name: the API requires each
            // part to be claimed by exactly one asset.
            $entries = new \stdClass();
            $parts = [];
            foreach ($pending as $index => [$name, $image]) {
                try {
                    $resolved = $image->resolve();
                } catch (TypeSafeException $error) {
                    throw new TypeSafeException(sprintf('Asset "%s": %s', $name, $error->getMessage()), 0, $error);
                }
                $part = 'asset-' . $index;
                $entries->{$name} = ['type' => 'image', 'part' => $part];
                $parts[] = ['name' => $part, 'contentType' => $resolved['contentType'], 'content' => $resolved['data'], 'filename' => $part];
            }
            $prepared['assets'] = $entries;
            [$contentType, $multipart] = Multipart::encode([
                ['name' => 'request', 'contentType' => 'application/json', 'content' => self::encodeJson($prepared), 'filename' => null],
                ...$parts,
            ]);

            // The empty Expect header stops cURL from waiting for a 100-continue on large bodies.
            return [$multipart, ['Content-Type' => $contentType, 'Expect' => '']];
        });
    }

    /**
     * @param array<string, string> $bodyHeaders Headers describing $body, such as its Content-Type.
     */
    private function request(string $method, string $path, ?string $body, array $bodyHeaders, ?RequestOptions $options): HttpResponse
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
        $encodedBody = $body;
        if ($encodedBody !== null) {
            $headers = self::mergeHeaders($headers, $bodyHeaders);
            // Compressed once, so every attempt sends the same bytes.
            if ($this->compression !== null && strlen($encodedBody) >= Compression::MIN_BODY_LENGTH) {
                $encodedBody = Compression::compress($encodedBody);
                $headers = self::mergeHeaders($headers, ['Content-Encoding' => $this->compression]);
            }
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
            match ($question['type']) {
                'choice' => QuestionRules::choice($name, $question['criteria']),
                'score' => QuestionRules::score($name, $question['criteria']),
                'noul' => QuestionRules::noul($name, $question['instructions'] ?? null, $question['criteria'] ?? null),
                default => null,
            };
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
            'box' => new BoxAnswer(
                self::boxCoordinates(self::stringMap(self::requiredMap($data, 'box'), 'box')),
                self::requiredNullableFloat($data, 'confidence'),
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

    /** @param array<string, mixed> $data */
    private static function requiredNullableFloat(array $data, string $key): ?float
    {
        // Unlike an optional value, a null one must be sent explicitly.
        if (!array_key_exists($key, $data)) {
            throw new \UnexpectedValueException(sprintf('missing %s', $key));
        }

        return $data[$key] === null ? null : self::requiredFloat($data, $key);
    }

    /** @param array<string, mixed> $box */
    private static function boxCoordinates(array $box): BoxCoordinates
    {
        return new BoxCoordinates(
            self::requiredFloat($box, 'top'),
            self::requiredFloat($box, 'bottom'),
            self::requiredFloat($box, 'left'),
            self::requiredFloat($box, 'right'),
        );
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

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new TypeSafeException('The request body could not be encoded as JSON.', 0, $error);
        }
    }

    /**
     * JSON has no NaN/Infinity tokens, so non-finite floats are sent as the strings "NaN",
     * "Infinity" and "-Infinity" instead of failing to encode. Questions and criteria objects are
     * expanded through jsonSerialize() and decoded JSON objects (stdClass) are mapped property by
     * property, so nested values are covered too.
     */
    private static function stringifyNonFinite(mixed $value): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
        }
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if ($value instanceof \stdClass) {
            return (object) array_map(self::stringifyNonFinite(...), get_object_vars($value));
        }
        if (is_array($value)) {
            return array_map(self::stringifyNonFinite(...), $value);
        }

        return $value;
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
