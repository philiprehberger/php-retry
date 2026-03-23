<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry;

final class RetryResult
{
    /**
     * Create a new retry result instance.
     *
     * @param  mixed  $value  The value returned by the operation.
     * @param  int  $attempts  The number of attempts made.
     * @param  float  $totalTimeMs  The total time spent retrying in milliseconds.
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $attempts,
        public readonly float $totalTimeMs,
    ) {}

    /**
     * Determine whether the operation was retried (more than one attempt).
     */
    public function wasRetried(): bool
    {
        return $this->attempts > 1;
    }

    /**
     * Get the total elapsed time across all attempts in milliseconds.
     */
    public function totalDuration(): float
    {
        return $this->totalTimeMs;
    }
}
