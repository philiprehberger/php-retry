# PHP Retry

[![Tests](https://github.com/philiprehberger/php-retry/actions/workflows/tests.yml/badge.svg)](https://github.com/philiprehberger/php-retry/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/philiprehberger/php-retry.svg)](https://packagist.org/packages/philiprehberger/php-retry)
[![License](https://img.shields.io/github/license/philiprehberger/php-retry)](LICENSE)

Composable retry utility with exponential backoff, jitter, and exception filtering.

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP         | ^8.2    |

## Installation

```bash
composer require philiprehberger/php-retry
```

## Usage

### Basic retry

```php
use PhilipRehberger\Retry\Retry;

$result = Retry::times(3)->run(function () {
    return file_get_contents('https://api.example.com/data');
});

echo $result->value;       // The response body
echo $result->attempts;    // Number of attempts made
echo $result->totalTimeMs; // Total time spent in milliseconds
```

### Exponential backoff with jitter

```php
$result = Retry::times(5)
    ->backoff(baseMs: 100, maxMs: 5000)
    ->jitter()
    ->run(fn () => $httpClient->get('/unstable-endpoint'));
```

### Linear backoff

```php
$result = Retry::times(3)
    ->linear(delayMs: 200)
    ->run(fn () => $api->call());
```

### Constant delay

```php
$result = Retry::times(4)
    ->constant(delayMs: 500)
    ->run(fn () => $service->fetch());
```

### Exception filtering

Only retry on specific exceptions:

```php
$result = Retry::times(5)
    ->onlyIf(fn (\Throwable $e) => $e instanceof ConnectionException)
    ->run(fn () => $db->query($sql));
```

Exclude specific exceptions from retrying:

```php
$result = Retry::times(5)
    ->except(ValidationException::class, AuthenticationException::class)
    ->run(fn () => $api->submit($data));
```

### Conditional Retry

Use `shouldRetry()` to provide a predicate that receives the exception and attempt number:

```php
$retry = Retry::times(5)
    ->shouldRetry(function (\Throwable $e, int $attempt): bool {
        // Stop retrying after attempt 3 for rate-limit errors
        if ($e instanceof RateLimitException && $attempt >= 3) {
            return false;
        }
        return true;
    })
    ->run(fn () => $api->request());
```

Use `retryOnlyOn()` to retry only for specific exception types:

```php
$result = Retry::times(5)
    ->retryOnlyOn(ConnectionException::class, TimeoutException::class)
    ->run(fn () => $httpClient->get('/endpoint'));
```

Retrieve the total number of attempts after execution with `getAttempts()`:

```php
$retry = Retry::times(5);
$result = $retry->run(fn () => $service->call());

echo $retry->getAttempts(); // e.g. 3
```

### Time budget

Stop retrying after a total time budget is exceeded:

```php
$result = Retry::times(100)
    ->constant(delayMs: 50)
    ->maxDuration(ms: 2000)
    ->run(fn () => $service->call());
```

### Retry forever (with safety)

```php
$result = Retry::forever()
    ->backoff(baseMs: 100, maxMs: 30000)
    ->jitter()
    ->maxDuration(ms: 60000)
    ->run(fn () => $queue->consume());
```

### Callbacks

```php
$result = Retry::times(5)
    ->backoff(baseMs: 100)
    ->beforeRetry(function (int $attempt, \Throwable $e) {
        logger()->warning("Retry attempt {$attempt}: {$e->getMessage()}");
    })
    ->afterRetry(function (int $attempt, ?\Throwable $e) {
        if ($e === null) {
            logger()->info("Succeeded on attempt {$attempt}");
        }
    })
    ->run(fn () => $api->request());
```

### Success callback

```php
$result = Retry::times(5)
    ->backoff(baseMs: 100)
    ->onSuccess(function (RetryResult $r) {
        logger()->info("Succeeded after {$r->attempts} attempt(s)");
    })
    ->run(fn () => $api->request());
```

### Checking retry status

```php
$result = Retry::times(3)->run(fn () => $service->call());

if ($result->wasRetried()) {
    logger()->warning("Operation required retries: {$result->attempts} attempts");
}

echo $result->totalDuration(); // Total elapsed time in milliseconds
```

## API

| Method | Description |
|--------|-------------|
| `Retry::times(int $maxAttempts)` | Create a retry builder with a maximum number of attempts |
| `Retry::forever()` | Create a retry builder that retries indefinitely |
| `->backoff(bool $exponential, int $baseMs, int $maxMs)` | Configure exponential backoff |
| `->linear(int $delayMs)` | Configure linear backoff |
| `->constant(int $delayMs)` | Configure constant delay |
| `->jitter(bool $enabled)` | Enable or disable jitter |
| `->onlyIf(callable $predicate)` | Only retry when predicate returns true |
| `->except(string ...$exceptionClasses)` | Exclude specific exception types from retrying |
| `->shouldRetry(callable $predicate)` | Predicate receiving exception and attempt number; return false to stop |
| `->retryOnlyOn(string ...$exceptionClasses)` | Only retry for the given exception types (sugar for `shouldRetry`) |
| `->getAttempts()` | Get the total number of attempts made after execution |
| `->maxDuration(int $ms)` | Set maximum total duration for all attempts |
| `->beforeRetry(callable $callback)` | Callback invoked before each retry |
| `->afterRetry(callable $callback)` | Callback invoked after each attempt |
| `->onSuccess(callable $callback)` | Callback invoked with `RetryResult` after successful execution |
| `->run(callable $operation)` | Execute the operation with retry logic |
| `$result->wasRetried()` | Returns `true` if more than one attempt was made |
| `$result->totalDuration()` | Total elapsed time across all attempts in milliseconds |

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## License

MIT
