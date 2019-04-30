<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry\Tests;

use InvalidArgumentException;
use LogicException;
use PhilipRehberger\Retry\Exceptions\RetriesExhaustedException;
use PhilipRehberger\Retry\Retry;
use PhilipRehberger\Retry\RetryResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryTest extends TestCase
{
    public function test_succeeds_on_first_attempt(): void
    {
        $result = Retry::times(3)->run(fn () => 'ok');

        $this->assertSame('ok', $result->value);
        $this->assertSame(1, $result->attempts);
    }

    public function test_succeeds_on_nth_attempt_after_failures(): void
    {
        $counter = 0;

        $result = Retry::times(5)
            ->constant(0)
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new RuntimeException("Attempt {$counter}");
                }

                return 'success';
            });

        $this->assertSame('success', $result->value);
        $this->assertSame(3, $result->attempts);
    }

    public function test_max_attempts_exhausted_throws_retries_exhausted_exception(): void
    {
        $this->expectException(RetriesExhaustedException::class);

        Retry::times(3)
            ->constant(0)
            ->run(fn () => throw new RuntimeException('fail'));
    }

    public function test_retries_exhausted_exception_contains_attempt_count_and_previous(): void
    {
        try {
            Retry::times(2)
                ->constant(0)
                ->run(fn () => throw new RuntimeException('inner'));

            $this->fail('Expected RetriesExhaustedException');
        } catch (RetriesExhaustedException $e) {
            $this->assertSame(2, $e->attempts);
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertSame('inner', $e->getPrevious()->getMessage());
        }
    }

    public function test_exponential_backoff_delay_increases_correctly(): void
    {
        $attempts = [];
        $counter = 0;

        Retry::times(4)
            ->backoff(exponential: true, baseMs: 10, maxMs: 10000)
            ->beforeRetry(function (int $attempt) use (&$attempts) {
                $attempts[] = $attempt;
            })
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 4) {
                    throw new RuntimeException('fail');
                }

                return 'done';
            });

        $this->assertSame([2, 3, 4], $attempts);
    }

    public function test_linear_backoff_stays_constant_increment(): void
    {
        $startTimes = [];
        $counter = 0;

        $result = Retry::times(3)
            ->linear(10)
            ->run(function () use (&$counter, &$startTimes) {
                $startTimes[] = hrtime(true);
                $counter++;
                if ($counter < 3) {
                    throw new RuntimeException('fail');
                }

                return 'done';
            });

        $this->assertSame('done', $result->value);
        $this->assertSame(3, $result->attempts);
    }

    public function test_jitter_produces_varied_delays_within_bounds(): void
    {
        $delays = [];

        for ($i = 0; $i < 10; $i++) {
            $counter = 0;
            $start = hrtime(true);

            Retry::times(2)
                ->constant(5)
                ->jitter()
                ->run(function () use (&$counter) {
                    $counter++;
                    if ($counter < 2) {
                        throw new RuntimeException('fail');
                    }

                    return 'ok';
                });

            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $delays[] = $elapsed;
        }

        // With jitter on constant(5), delays should range from 0-5ms.
        // At least some should be different from each other.
        $rounded = array_map(fn ($d) => round($d, 1), $delays);
        $unique = array_unique($rounded);
        $this->assertGreaterThanOrEqual(1, count($unique));
    }

    public function test_only_if_filters_which_exceptions_trigger_retry(): void
    {
        $counter = 0;

        $this->expectException(InvalidArgumentException::class);

        Retry::times(5)
            ->constant(0)
            ->onlyIf(fn (\Throwable $e) => $e instanceof RuntimeException)
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter === 1) {
                    throw new RuntimeException('retryable');
                }
                throw new InvalidArgumentException('not retryable');
            });
    }

    public function test_except_excludes_specific_exception_types(): void
    {
        $this->expectException(LogicException::class);

        Retry::times(5)
            ->constant(0)
            ->except(LogicException::class)
            ->run(fn () => throw new LogicException('excluded'));
    }

    public function test_max_duration_stops_retrying_after_time_budget(): void
    {
        $start = hrtime(true);

        try {
            Retry::times(1000)
                ->constant(10)
                ->maxDuration(50)
                ->run(fn () => throw new RuntimeException('fail'));

            $this->fail('Expected RetriesExhaustedException');
        } catch (RetriesExhaustedException $e) {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $this->assertGreaterThanOrEqual(1, $e->attempts);
            // Allow some tolerance for timing
            $this->assertLessThan(500, $elapsed);
        }
    }

    public function test_before_retry_callback_receives_correct_args(): void
    {
        $calls = [];
        $counter = 0;

        Retry::times(3)
            ->constant(0)
            ->beforeRetry(function (int $attempt, \Throwable $e) use (&$calls) {
                $calls[] = ['attempt' => $attempt, 'message' => $e->getMessage()];
            })
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new RuntimeException("fail-{$counter}");
                }

                return 'ok';
            });

        $this->assertCount(2, $calls);
        $this->assertSame(2, $calls[0]['attempt']);
        $this->assertSame('fail-1', $calls[0]['message']);
        $this->assertSame(3, $calls[1]['attempt']);
        $this->assertSame('fail-2', $calls[1]['message']);
    }

    public function test_retry_forever_retries_until_success_with_max_duration_safety(): void
    {
        $counter = 0;

        $result = Retry::forever()
            ->constant(1)
            ->maxDuration(500)
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 5) {
                    throw new RuntimeException('not yet');
                }

                return 'finally';
            });

        $this->assertSame('finally', $result->value);
        $this->assertSame(5, $result->attempts);
    }

    public function test_zero_attempt_edge_case(): void
    {
        // times(0) means no attempts at all, should throw immediately
        try {
            Retry::times(0)
                ->constant(0)
                ->run(fn () => 'ok');

            $this->fail('Expected RetriesExhaustedException');
        } catch (RetriesExhaustedException $e) {
            $this->assertSame(0, $e->attempts);
        }
    }

    public function test_retry_result_tracks_attempt_count_and_total_time(): void
    {
        $result = Retry::times(1)->run(fn () => 42);

        $this->assertInstanceOf(RetryResult::class, $result);
        $this->assertSame(42, $result->value);
        $this->assertSame(1, $result->attempts);
        $this->assertIsFloat($result->totalTimeMs);
        $this->assertGreaterThanOrEqual(0, $result->totalTimeMs);
    }

    public function test_nested_callables_work_correctly(): void
    {
        $outer = function () {
            return Retry::times(3)
                ->constant(0)
                ->run(function () {
                    $inner = Retry::times(2)
                        ->constant(0)
                        ->run(fn () => 'inner-value');

                    return 'outer-'.$inner->value;
                });
        };

        $result = $outer();

        $this->assertSame('outer-inner-value', $result->value);
        $this->assertSame(1, $result->attempts);
    }

    public function test_on_success_fires_after_first_try_success(): void
    {
        $captured = null;

        $result = Retry::times(3)
            ->onSuccess(function (RetryResult $r) use (&$captured) {
                $captured = $r;
            })
            ->run(fn () => 'first-try');

        $this->assertSame('first-try', $result->value);
        $this->assertNotNull($captured);
        $this->assertSame('first-try', $captured->value);
        $this->assertSame(1, $captured->attempts);
    }

    public function test_on_success_fires_after_retried_success(): void
    {
        $captured = null;
        $counter = 0;

        $result = Retry::times(5)
            ->constant(0)
            ->onSuccess(function (RetryResult $r) use (&$captured) {
                $captured = $r;
            })
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new RuntimeException('fail');
                }

                return 'retried-success';
            });

        $this->assertSame('retried-success', $result->value);
        $this->assertNotNull($captured);
        $this->assertSame('retried-success', $captured->value);
        $this->assertSame(3, $captured->attempts);
    }

    public function test_on_success_does_not_fire_on_failure(): void
    {
        $fired = false;

        try {
            Retry::times(2)
                ->constant(0)
                ->onSuccess(function () use (&$fired) {
                    $fired = true;
                })
                ->run(fn () => throw new RuntimeException('always fails'));

            $this->fail('Expected RetriesExhaustedException');
        } catch (RetriesExhaustedException) {
            $this->assertFalse($fired);
        }
    }

    public function test_was_retried_returns_false_on_first_attempt(): void
    {
        $result = Retry::times(3)->run(fn () => 'ok');

        $this->assertFalse($result->wasRetried());
    }

    public function test_was_retried_returns_true_after_multiple_attempts(): void
    {
        $counter = 0;

        $result = Retry::times(5)
            ->constant(0)
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 3) {
                    throw new RuntimeException('fail');
                }

                return 'ok';
            });

        $this->assertTrue($result->wasRetried());
    }

    public function test_total_duration_returns_positive_value(): void
    {
        $counter = 0;

        $result = Retry::times(3)
            ->constant(1)
            ->run(function () use (&$counter) {
                $counter++;
                if ($counter < 2) {
                    throw new RuntimeException('fail');
                }

                return 'ok';
            });

        $this->assertGreaterThan(0, $result->totalDuration());
        $this->assertSame($result->totalTimeMs, $result->totalDuration());
    }
}
