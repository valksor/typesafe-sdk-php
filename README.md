# typesafe-sdk-php

[![CI](https://github.com/valksor/typesafe-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/valksor/typesafe-sdk-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/valksor/typesafe-sdk-php)](https://packagist.org/packages/valksor/typesafe-sdk-php)
[![PHP Version](https://img.shields.io/packagist/dependency-v/valksor/typesafe-sdk-php/php)](https://packagist.org/packages/valksor/typesafe-sdk-php)

A modern PHP client for the [TypeSafe AI](https://typesafe.ai) System One API.

> [!IMPORTANT]
> **Unofficial and unaffiliated.** This project is **not** created, maintained,
> endorsed by, or associated with TypeSafe AI in any way. It is an independent,
> community-maintained SDK that aims for **1:1 feature parity** with the official
> [JavaScript](https://github.com/typesafe-ai/typesafe-sdk-js) and
> [Python](https://github.com/typesafe-ai/typesafe-sdk-python) SDKs — the same
> wire contract, environment configuration, retry semantics, request metadata,
> and typed errors — written in idiomatic, strictly-typed PHP. All product names,
> logos, and brands are property of their respective owners.

## Features

- Typed `Noul`, `Choice`, and `Score` questions and their answers
- Single `systemOne()` call with typed answer lookup methods
- Optional typed response models via a `ResponseModel` factory
- Model discovery via `$client->models->list()`
- Environment-based configuration with explicit overrides
- Configurable per-attempt timeouts and exponential-backoff retries
- Request IDs and raw response metadata on every call
- Injectable `Transport` (default cURL) for framework integration and tests
- Status-specific typed exceptions
- Strictly typed, PHPStan max level, zero framework dependencies

## Install

```sh
composer require valksor/typesafe-sdk-php
```

Requires PHP 8.4 or newer with the `curl` and `json` extensions.

## Quick start

Set `TYPESAFE_API_KEY`, then ask typed questions:

```php
<?php

declare(strict_types=1);

use TypeSafe\Choice;
use TypeSafe\Client;

$client = new Client();
$response = $client->systemOne(
    state: ['document' => 'I was charged twice. Please fix this ASAP.'],
    questions: [
        'category' => new Choice(
            instructions: 'What is this ticket about?',
            criteria: ['billing' => null, 'technical' => null, 'other' => null],
        ),
    ],
);

$category = $response->choice('category');
echo $category?->choice;
```

`Noul`, `Choice`, and `Score` represent the three question types. Responses keep
all answers in `SystemOneResponse::$answers` and provide typed lookup methods
(`noul()`, `choice()`, `score()`). List available models with
`$client->models->list()`.

## Typed response models

Pass a `class-string` implementing `ResponseModel` as `responseModel:` to receive
a typed model of your own instead of a `SystemOneResponse`. The model's
`fromSystemOne()` factory pulls the answers it cares about into its own
properties:

```php
<?php

declare(strict_types=1);

use TypeSafe\Choice;
use TypeSafe\Client;
use TypeSafe\Noul;
use TypeSafe\ResponseModel;
use TypeSafe\SystemOneResponse;

final readonly class Triage implements ResponseModel
{
    public function __construct(
        public float $spam,
        public string $category,
    ) {}

    public static function fromSystemOne(SystemOneResponse $response): static
    {
        // Extract null-safely: an omitted or unknown answer returns null.
        $spam = $response->noul('spam')
            ?? throw new \UnexpectedValueException('missing answer "spam"');
        $category = $response->choice('category')
            ?? throw new \UnexpectedValueException('missing answer "category"');

        return new self(spam: $spam->noul, category: $category->choice);
    }
}

$client = new Client();
$triage = $client->systemOne(
    state: ['document' => 'I was charged twice.'],
    questions: [
        'spam' => new Noul('Is this spam?'),
        'category' => new Choice('What is this about?', ['billing' => null, 'other' => null]),
    ],
    responseModel: Triage::class,
);
echo $triage->category;
```

Throw `\UnexpectedValueException` from `fromSystemOne()` to signal a validation
failure; the SDK rewraps it as a `ResponseValidationError` carrying the raw
response. Any other exception type propagates unchanged, so a genuine bug in your
model class is not misreported as a bad response. Unrecognized answer types are
ignored, so a future answer type your model does not read causes no failure.

> **Security:** `responseModel:` must be a trusted, developer-literal class name.
> Never derive it from request or user input — a dynamic class-string would
> invoke an arbitrary `fromSystemOne()` method (type confusion).

## Configuration

The `Client` constructor uses named arguments. Explicit values take precedence
over environment variables:

| Setting               | Environment variable      | Default                    |
| --------------------- | ------------------------- | -------------------------- |
| API key (required)    | `TYPESAFE_API_KEY`        | —                          |
| Base URL              | `TYPESAFE_BASE_URL`       | `https://api.typesafe.ai`  |
| Default model         | `TYPESAFE_DEFAULT_MODEL`  | `jev-latest`               |

The default timeout is 10 seconds per attempt. The client retries HTTP 408, 429,
and 5xx responses, connection errors, and timeouts twice with exponential
backoff. Use `RetryPolicy` for client-wide settings or `RequestOptions` for a
single call. A custom `Transport` can be injected for framework integration and
tests.

The API key is validated in the constructor: it is trimmed and must be non-empty
printable ASCII with no whitespace. A key containing whitespace, control, or
non-ASCII characters throws a `TypeSafeException`.

### AI gateways

To route requests through an OpenAI-style AI gateway or reverse proxy, point the
client at the gateway with the `baseUrl:` argument (or the `TYPESAFE_BASE_URL`
environment variable). The gateway forwards the `Authorization` header and other
request headers unchanged:

```php
$client = new Client(
    apiKey: getenv('TYPESAFE_API_KEY') ?: null,
    baseUrl: 'https://gateway.example.com/typesafe',
);
```

## Error handling

HTTP failures throw `ApiError` subclasses:

```php
use TypeSafe\ApiError;
use TypeSafe\RateLimitError;

try {
    $response = $client->systemOne(state: $state, questions: $questions);
} catch (RateLimitError $e) {
    // back off and retry
} catch (ApiError $e) {
    error_log("api error {$e->statusCode} (request {$e->requestId}): {$e->getMessage()}");
}
```

Status codes map to `BadRequestError` (400), `AuthenticationError` (401),
`PermissionDeniedError` (403), `NotFoundError` (404), `ConflictError` (409),
`UnprocessableEntityError` (422), `RateLimitError` (429), and
`InternalServerError` (5xx). Transport-level failures throw `APIConnectionError`
and `APITimeoutError`. Successful responses with an invalid shape — and
`responseModel` factories that report a validation failure — throw
`ResponseValidationError`, which exposes the raw `$response`. Avoid logging that
body unredacted in production; it echoes the request `state`, which may contain
sensitive data.

## Documentation

- API wire contract: [TypeSafe API docs](https://docs.typesafe.ai/api)

## Testing

```sh
composer test        # PHPUnit
composer analyse     # PHPStan (max level)
composer lint        # php -l syntax check
```

Live integration tests are opt-in and read-only (they exercise `GET /v1/models`):

```sh
TYPESAFE_RUN_LIVE_TESTS=1 TYPESAFE_API_KEY=... composer test
```

## Releases

This SDK tracks the same release version line as the official JavaScript and
Python SDKs. `Client::VERSION`, the latest changelog entry, and the Git tag must
all match exactly — for example, release `0.6.0` with tag `v0.6.0`. See
[docs/changelog.md](docs/changelog.md).

CI tests PHP 8.4 and 8.5. The publish workflow can be run manually as a dry run;
pushing the matching `vX.Y.Z` tag from the default branch creates a tested
Composer archive and GitHub Release. Configure the package's Packagist GitHub
integration so Packagist consumes that same tag automatically.

## License

[MIT](LICENSE)
