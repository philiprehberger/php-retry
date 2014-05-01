<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry\Exceptions;

use RuntimeException;
use Throwable;

final class RetriesExhaustedException extends RuntimeException
{
    /**
     * Create a new retries exhausted exception instance.
     *
     * @param  int  $attempts  The number of attempts made before giving up.
     * @param  Throwable|null  $previous  The last exception thrown by the operation.
     */
    public function __construct(
        public readonly int $attempts,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Retries exhausted after {$attempts} attempt(s).",
            0,
            $previous,
        );
    }
}
