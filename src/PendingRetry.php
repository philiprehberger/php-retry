<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry;

use PhilipRehberger\Retry\Exceptions\RetriesExhaustedException;
use Throwable;

final class PendingRetry
{
    private BackoffStrategy $strategy = BackoffStrategy::Exponential;

    private int $baseMs = 100;

    private int $maxMs = 10000;

    private bool $jitterEnabled = false;

    /** @var callable|null */
    private mixed $predicate = null;

    /** @var list<class-string<Throwable>> */
    private array $exceptClasses = [];

    /** @var callable(Throwable, int): bool|null */
    private mixed $shouldRetryPredicate = null;

    private ?int $maxDurationMs = null;

    /** @var callable|null */
    private mixed $beforeRetryCallback = null;

    /** @var callable|null */
    private mixed $afterRetryCallback = null;

    /** @var callable|null */
    private mixed $onSuccessCallback = null;

    private int $attemptsMade = 0;

    /**
     * Create a new pending retry instance.
     *
     * @param  int  $maxAttempts  The maximum number of attempts.
     */
    public function __construct(
        private readonly int $maxAttempts,
    ) {}

    /**
     * Configure exponential backoff with the given base and maximum delay.
     *
     * @param  bool  $exponential  Whether to use exponential backoff (always true for this method).
     * @param  int  $baseMs  The base delay in milliseconds.
     * @param  int  $maxMs  The maximum delay in milliseconds.
     * @return self The current instance for fluent chaining.
     */
    public function backoff(bool $exponential = true, int $baseMs = 100, int $maxMs = 10000): self
    {
        $this->strategy = $exponential ? BackoffStrategy::Exponential : BackoffStrategy::Constant;
        $this->baseMs = $baseMs;
        $this->maxMs = $maxMs;

        return $this;
    }

    /**
     * Configure linear backoff where delay increases by a fixed increment each attempt.
     *
     * @param  int  $delayMs  The delay increment in milliseconds.
     * @return self The current instance for fluent chaining.
     */
    public function linear(int $delayMs = 100): self
    {
        $this->strategy = BackoffStrategy::Linear;
        $this->baseMs = $delayMs;

        return $this;
    }

    /**
     * Configure constant backoff where delay stays the same each attempt.
     *
     * @param  int  $delayMs  The fixed delay in milliseconds.
     * @return self The current instance for fluent chaining.
     */
    public function constant(int $delayMs = 100): self
    {
        $this->strategy = BackoffStrategy::Constant;
        $this->baseMs = $delayMs;

        return $this;
    }

    /**
     * Enable or disable jitter to randomize delay within bounds.
     *
     * @param  bool  $enabled  Whether jitter should be enabled.
     * @return self The current instance for fluent chaining.
     */
    public function jitter(bool $enabled = true): self
    {
        $this->jitterEnabled = $enabled;

        return $this;
    }

    /**
     * Only retry when the given predicate returns true for the thrown exception.
     *
     * @param  callable(Throwable): bool  $predicate  A function that receives the exception and returns whether to retry.
     * @return self The current instance for fluent chaining.
     */
    public function onlyIf(callable $predicate): self
    {
        $this->predicate = $predicate;

        return $this;
    }

    /**
     * Exclude specific exception types from being retried.
     *
     * @param  class-string<Throwable>  ...$exceptionClasses  Exception class names that should not trigger a retry.
     * @return self The current instance for fluent chaining.
     */
    public function except(string ...$exceptionClasses): self
    {
        $this->exceptClasses = array_values($exceptionClasses);

        return $this;
    }

    /**
     * Set a maximum total duration for all retry attempts combined.
     *
     * @param  int  $ms  The maximum duration in milliseconds.
     * @return self The current instance for fluent chaining.
     */
    public function maxDuration(int $ms): self
    {
        $this->maxDurationMs = $ms;

        return $this;
    }

    /**
     * Register a callback to be invoked before each retry attempt.
     *
     * @param  callable(int, Throwable): void  $callback  Receives the attempt number (1-based) and the exception.
     * @return self The current instance for fluent chaining.
     */
    public function beforeRetry(callable $callback): self
    {
        $this->beforeRetryCallback = $callback;

        return $this;
    }

    /**
     * Register a callback to be invoked after each retry attempt completes (success or failure).
     *
     * @param  callable(int, ?Throwable): void  $callback  Receives the attempt number and the exception (null on success).
     * @return self The current instance for fluent chaining.
     */
    public function afterRetry(callable $callback): self
    {
        $this->afterRetryCallback = $callback;

        return $this;
    }

    /**
     * Register a callback to be invoked after the operation succeeds.
     *
     * @param  callable(RetryResult): void  $callback  Receives the RetryResult after successful execution.
     * @return self The current instance for fluent chaining.
     */
    public function onSuccess(callable $callback): self
    {
        $this->onSuccessCallback = $callback;

        return $this;
    }

    /**
     * Set a predicate that determines whether to retry after a failure.
     *
     * The callable receives the thrown exception and the current attempt number (1-based).
     * Return true to continue retrying, or false to stop immediately and rethrow.
     *
     * @param  callable(Throwable, int): bool  $predicate  A function that decides whether to retry.
     * @return static The current instance for fluent chaining.
     */
    public function shouldRetry(callable $predicate): static
    {
        $this->shouldRetryPredicate = $predicate;

        return $this;
    }

    /**
     * Only retry when the thrown exception is an instance of one of the given classes.
     *
     * This is sugar for shouldRetry() with an instanceof check.
     *
     * @param  class-string<Throwable>  ...$exceptionClasses  Exception class names that should trigger a retry.
     * @return static The current instance for fluent chaining.
     */
    public function retryOnlyOn(string ...$exceptionClasses): static
    {
        return $this->shouldRetry(function (Throwable $e) use ($exceptionClasses): bool {
            foreach ($exceptionClasses as $class) {
                if ($e instanceof $class) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Get the total number of attempts made during the last execution.
     *
     * Returns 0 if run() has not been called yet.
     *
     * @return int The total attempt count.
     */
    public function getAttempts(): int
    {
        return $this->attemptsMade;
    }

    /**
     * Execute the operation with the configured retry logic.
     *
     * @param  callable(): mixed  $operation  The operation to attempt.
     * @return RetryResult The result containing the return value, attempt count, and total time.
     *
     * @throws RetriesExhaustedException When all attempts are exhausted or the time budget is exceeded.
     * @throws Throwable When an exception is not retryable.
     */
    public function run(callable $operation): RetryResult
    {
        $startTime = hrtime(true);
        $lastException = null;
        $attempt = 0;
        $this->attemptsMade = 0;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            if ($this->maxDurationMs !== null && $attempt > 1) {
                $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;

                if ($elapsedMs >= $this->maxDurationMs) {
                    $this->attemptsMade = $attempt - 1;
                    throw new RetriesExhaustedException($attempt - 1, $lastException);
                }
            }

            try {
                $result = $operation();

                $this->attemptsMade = $attempt;
                $totalTimeMs = (hrtime(true) - $startTime) / 1_000_000;

                if ($this->afterRetryCallback !== null && $attempt > 1) {
                    ($this->afterRetryCallback)($attempt, null);
                }

                $retryResult = new RetryResult(
                    value: $result,
                    attempts: $attempt,
                    totalTimeMs: $totalTimeMs,
                );

                if ($this->onSuccessCallback !== null) {
                    ($this->onSuccessCallback)($retryResult);
                }

                return $retryResult;
            } catch (Throwable $e) {
                $lastException = $e;
                $this->attemptsMade = $attempt;

                if ($this->afterRetryCallback !== null) {
                    ($this->afterRetryCallback)($attempt, $e);
                }

                if (! $this->isRetryable($e)) {
                    throw $e;
                }

                if ($this->shouldRetryPredicate !== null && ! ($this->shouldRetryPredicate)($e, $attempt)) {
                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                if ($this->beforeRetryCallback !== null) {
                    ($this->beforeRetryCallback)($attempt + 1, $e);
                }

                $this->sleep($attempt);
            }
        }

        $this->attemptsMade = $attempt;
        throw new RetriesExhaustedException($attempt, $lastException);
    }

    /**
     * Determine whether the given exception should trigger a retry.
     */
    private function isRetryable(Throwable $e): bool
    {
        foreach ($this->exceptClasses as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        if ($this->predicate !== null) {
            return ($this->predicate)($e);
        }

        return true;
    }

    /**
     * Sleep for the calculated delay based on the current attempt and backoff strategy.
     */
    private function sleep(int $attempt): void
    {
        $delayMs = $this->calculateDelay($attempt);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * Calculate the delay in milliseconds for the given attempt number.
     */
    private function calculateDelay(int $attempt): int
    {
        $delay = match ($this->strategy) {
            BackoffStrategy::Exponential => (int) min($this->baseMs * (2 ** ($attempt - 1)), $this->maxMs),
            BackoffStrategy::Linear => $this->baseMs * $attempt,
            BackoffStrategy::Constant => $this->baseMs,
        };

        if ($this->jitterEnabled) {
            $delay = random_int(0, $delay);
        }

        return $delay;
    }
}
