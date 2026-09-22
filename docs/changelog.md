# Changelog

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
