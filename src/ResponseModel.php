<?php

declare(strict_types=1);

namespace TypeSafe;

/**
 * Contract for user-defined response models passed to {@see Client::systemOne()}
 * via its `responseModel:` argument.
 *
 * The SDK first decodes the wire response into a {@see SystemOneResponse} exactly
 * as it always has, then hands that fully typed object to this factory. The
 * factory performs its own typed extraction and validation and returns an
 * instance of itself, "lifting" the answers it cares about into its own
 * properties (for example via `$response->noul('spam')`).
 *
 * Validation-failure contract: throw {@see \UnexpectedValueException} from
 * {@see self::fromSystemOne()} to signal that the response does not match the
 * model; the SDK rewraps it as a {@see ResponseValidationError} carrying the raw
 * response. Any other exception type propagates unchanged, so genuine bugs in the
 * model class are not misclassified as validation failures.
 */
interface ResponseModel
{
    /**
     * Build the model from a fully decoded System One response.
     */
    public static function fromSystemOne(SystemOneResponse $response): static;
}
