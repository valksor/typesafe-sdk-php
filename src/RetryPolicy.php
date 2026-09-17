<?php

declare(strict_types=1);

namespace TypeSafe;

final readonly class RetryPolicy
{
    /**
     * @param array<int, true>|null $httpStatuses
     */
    public function __construct(
        public int $maxRetries = 2,
        public float $backoffInitial = 0.5,
        public float $backoffMax = 5.0,
        public float $backoffJitter = 0.25,
        public ?array $httpStatuses = null,
        public bool $respectRetryAfter = true,
        public float $maxRetryAfter = 60.0,
        public bool $apiConnectionError = true,
        public bool $apiTimeoutError = true,
    ) {
        if ($maxRetries < 0) {
            throw new TypeSafeException('maxRetries must be non-negative.');
        }
        foreach ([$backoffInitial, $backoffMax, $maxRetryAfter] as $duration) {
            if (!is_finite($duration) || $duration < 0) {
                throw new TypeSafeException('Retry durations must be non-negative finite values.');
            }
        }
        if (!is_finite($backoffJitter) || $backoffJitter < 0 || $backoffJitter > 1) {
            throw new TypeSafeException('backoffJitter must be between zero and one.');
        }
        foreach (array_keys($httpStatuses ?? []) as $status) {
            if ($status < 100 || $status > 999) {
                throw new TypeSafeException(sprintf('Invalid retry HTTP status %d.', $status));
            }
        }
    }

    /** @return array<int, true> */
    public function statuses(): array
    {
        if ($this->httpStatuses !== null) {
            return $this->httpStatuses;
        }
        $statuses = [408 => true, 429 => true];
        foreach (range(500, 599) as $status) {
            $statuses[$status] = true;
        }
        return $statuses;
    }
}
