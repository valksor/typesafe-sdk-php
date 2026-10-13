# Changelog

## v0.7.4 (2026-10-13)

- Track upstream Python SDK 0.7.4 (covers 0.7.2–0.7.4).
- `Client::systemOne()` rejects a `null` state with a `TypeSafeException` ("State is required.")
  before any request is sent.
- Reject `Choice` questions, typed or raw, with empty or `null` criteria: `Choice question "<name>" has
  no criteria; at least one choice is required.`
- Validate `Noul` questions, typed or raw: criteria that are not an object are rejected
  (`criteria must be an object`), and so is a question with neither instructions nor a described
  outcome (`has neither instructions nor criteria; at least one is required`). A criteria map whose
  `true` and `false` are both `null`, or an empty one, counts as no criteria.
- Behaviour change: a `Score` question now needs at least one criterion instead of two, matching
  upstream. An empty criteria list is rejected with `Score question "<name>" has no criteria; at least one
  score is required.` Validation of typed questions moves from the `Score` constructor to
  `Score::validate()` so the message can name the question.
- Non-finite floats anywhere in the request body (state, question instructions and criteria, raw
  questions, `extraBody`) are sent as the strings `"NaN"`, `"Infinity"` and `"-Infinity"` instead of
  failing to encode. Finite values encode exactly as before.
- `CurlTransport` keeps one cURL handle per transport and resets it between requests, so repeated
  requests reuse the TLS connection instead of opening a new one each time. The connection cache
  holds up to 100 connections (`CURLOPT_MAXCONNECTS`) and idle connections expire after 30 s
  (`CURLOPT_MAXAGE_CONN`), matching upstream's pool size and keepalive expiry.
- Not ported: upstream's truststore TLS lock only guards a Python/AnyIO threading bug and has no PHP
  equivalent. The `http2` extra is Python packaging; the PHP SDK needs no opt-in, since libcurl
  negotiates HTTP/2 over TLS on its own when the installed cURL build supports it.

## v0.7.1 (2026-09-22)

- Track upstream Python SDK 0.7.1. No wire-contract change.
- Validate the API key in `Client::__construct()`: the resolved key is trimmed and rejected with
  a `TypeSafeException` if it is empty or contains whitespace, control characters, or non-ASCII
  characters. A malformed credential now fails fast at construction instead of being placed into a
  broken `Authorization` header, where whitespace or control bytes could split request headers.
- Document pointing the client at an OpenAI-style AI gateway by passing `baseUrl:` (or setting
  `TYPESAFE_BASE_URL`).
- Upstream's exception-redaction fix masks credential values that Python's `httpx` can embed in a
  transport exception's message or chain. PHP's cURL transport reports connection and timeout
  failures via `curl_error()`, which describes the transport fault and never echoes request header
  values, so an `APIConnectionError` or `APITimeoutError` from this SDK cannot contain the API key;
  there is no equivalent to port beyond the early validation above.

## v0.7.0 (2026-09-19)

- Track upstream Python and JavaScript SDK 0.7.0. No wire-contract change.
- Add an optional `responseModel:` argument to `Client::systemOne()`: pass a `class-string`
  implementing the new `ResponseModel` interface to receive a typed, user-defined model instead
  of a `SystemOneResponse`. The model's `fromSystemOne()` factory extracts and validates typed
  answers, lifting the ones it declares into its own properties; unrecognized answer types are
  ignored for forward compatibility. Validation failures thrown as `\UnexpectedValueException`
  (or `ResponseValidationError`) are raised as `ResponseValidationError` carrying the raw
  response; non-2xx responses still raise the usual typed API errors.
- The native return type widens to `SystemOneResponse|ResponseModel`; existing callers that omit
  `responseModel:` are narrowed back to `SystemOneResponse` by a PHPStan conditional-return
  docblock, so non-PHPStan tools may display the union. No runtime change for existing callers.
- Upstream 0.7.0's msgspec-to-pydantic swap and its str-subclass serialization fix are
  Python-internal and do not apply to PHP.

## v0.6.0 (2026-09-17)

- Initial PHP SDK release with typed Noul, Choice, and Score questions and answers.
- Add configurable retries, timeouts, request metadata, model discovery, and typed API exceptions.
- Add injectable transport support, a cURL transport, and opt-in live integration testing.
