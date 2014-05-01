<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry;

final class Retry
{
    /**
     * Create a pending retry that will attempt the operation up to the given number of times.
     *
     * @param  int  $maxAttempts  The maximum number of attempts (must be >= 1).
     * @return PendingRetry A fluent retry builder.
     */
    public static function times(int $maxAttempts): PendingRetry
    {
        return new PendingRetry(maxAttempts: $maxAttempts);
    }

    /**
     * Create a pending retry that will attempt the operation indefinitely until it succeeds.
     *
     * Should be paired with maxDuration() to prevent infinite loops.
     *
     * @return PendingRetry A fluent retry builder with unlimited attempts.
     */
    public static function forever(): PendingRetry
    {
        return new PendingRetry(maxAttempts: PHP_INT_MAX);
    }
}
