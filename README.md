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

- Typed `Noul`, `Choice`, `Score`, and `Box` questions and their answers
- Image inputs, passed, placed in content, or referenced in text
- Single `systemOne()` call with typed answer lookup methods
- Optional typed response models via a `ResponseModel` factory
- Model discovery via `$client->models->list()`
- Environment-based configuration with explicit overrides
- Configurable per-attempt timeouts and exponential-backoff retries
- zstd request-body compression when `ext-zstd` is loaded
- Request IDs and raw response metadata on every call
- Injectable `Transport` (default cURL) for framework integration and tests
- Status-specific typed exceptions
- Strictly typed, PHPStan max level, zero framework dependencies

## Install

```sh
composer require valksor/typesafe-sdk-php
```

Requires PHP 8.4 or newer with the `curl` and `json` extensions. The optional `zstd`
extension ([`ext-zstd`](https://pecl.php.net/package/zstd)) compresses large request
bodies; see [Compression](#compression).

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

## Images

Image input is an experimental API feature that currently needs the
`TypeSafe-Danger-Zone-Images: true` header, and it requires a vision-language
model. Pass the header with the `headers:` constructor argument:

```php
<?php

declare(strict_types=1);

use TypeSafe\Client;
use TypeSafe\Image;
use TypeSafe\Noul;

$client = new Client(headers: ['TypeSafe-Danger-Zone-Images' => 'true']);

$response = $client->systemOne(
    state: ['Here is the returned item:', Image::fromFile('photos/product.png')],
    questions: ['damaged' => new Noul('Is the item damaged?')],
);
```

Create an `Image` with `Image::fromBytes()` (the bytes of an image file),
`Image::fromFile()` (a path, read when the image is first used), or
`Image::fromBase64()` (base64 text, such as an upload; whitespace is ignored and
padding is required, and invalid text throws a `TypeSafeException`). An image is
read once, on first use, and every request that sends the same `Image` object
reuses it. PNG and JPEG are recognized by their signature; other formats are sent
as generic binary data and left for the API to accept or reject. Pillow images and
the Python buffer protocol have no PHP equivalent.

An image can be:

- **Placed** anywhere in the state, or in a question's instructions or criteria
  (including raw question arrays). It is sent as an upload, and its unique
  reference replaces it.
- **Passed** by alias in `assets:`, an array of `Image` objects. The API replaces
  an alias wherever it appears **as or inside** any string, so aliases must be
  unique placeholders such as `<photo>` or a UUID, never plain words. An image
  placed in the content that is also passed is sent under its alias only. An image
  under several aliases is sent once per alias.
- **Referenced in text** by its reference: cast the image to a string (`"{$photo}"`).
  The image is sent when it is attached with `attachAssets()`, passed, or placed
  elsewhere in the request. Text that merely looks like a reference stays text.

```php
$photo = Image::fromFile('photos/product.png');

$response = $client->attachAssets($photo, fn () => $client->systemOne(
    state: "The customer says {$photo} arrived scratched.",
    questions: ['damaged' => new Noul("Is the item in {$photo} damaged?")],
));
```

Attached images are client-wide: every call the client makes meanwhile shares them,
and overlapping attachments of an image keep it attached until the last one ends.
If one client serves several tenants, pass `assets:` per call instead. A request
with an `assets` key in `extraBody` is sent as given, and no image is read.

Images are uploaded to the TypeSafe API as `multipart/form-data` parts, and they
are held whole in memory. They are sent as-is, including EXIF metadata such as
GPS location, so strip metadata you don't need before sending. `Image::fromFile()`
is a local file read: never pass an untrusted path (URL wrappers are rejected).

## Box

A `Box` question locates what its instructions describe in the request's image.
The answer is a `BoxAnswer`: the box's `top`, `bottom`, `left`, and `right` edges
as fractions of the image's height and width (0 to 1), and a `confidence` that is
`null` when the model does not provide one. Box questions currently need the
`jev-preview` model: the API rejects them on the default model.

```php
use TypeSafe\Box;

$response = $client->systemOne(
    state: Image::fromFile('photos/dog.jpg'),
    questions: ['dog' => new Box('The dog')],
    model: 'jev-preview',
);

$dog = $response->box('dog');
echo $dog?->box->left, ' ', $dog?->box->top, ' ', $dog?->confidence ?? 'n/a';
```

## Compression

With `ext-zstd` loaded, request bodies of 1 KiB or more (multipart uploads
included) are compressed once per request with zstd and sent with
`Content-Encoding: zstd`; retries send the same bytes. Smaller bodies and
requests without a body are never compressed.

The `compression:` constructor argument controls this: `null` (the default) uses
zstd when `ext-zstd` is loaded and sends plain bodies otherwise, `'zstd'` requires
the extension and throws a `TypeSafeException` without it, and `false` never
compresses.

> **Behaviour change in 0.7.5:** with `ext-zstd` loaded, large bodies are now
> compressed by default. The TypeSafe API accepts them, but non-official APIs,
> proxies, and self-hosted endpoints may not. Pass `compression: false` to opt
> out. If a body mixes confidential state with attacker-controlled text, opt out
> too, to avoid a compression length side channel.

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

Live integration tests are opt-in. They call `GET /v1/models` and `POST /v1/systemone`
(with and without compression):

```sh
TYPESAFE_RUN_LIVE_TESTS=1 TYPESAFE_API_KEY=... composer test
```

The live image tests also need `TYPESAFE_LIVE_IMAGES=1`, and an API that accepts
the experimental image header.

Tests that need the real `ext-zstd` are skipped when it is not loaded; CI installs it.

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
