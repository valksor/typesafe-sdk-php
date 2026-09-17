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
and `APITimeoutError`. Successful responses with an invalid shape throw
`ResponseValidationError`.

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
