# Changelog

All notable changes to `php-retry` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-03-23

### Changed
- Standardize README requirements format per template guide

## [1.2.0] - 2026-03-22

### Added
- `onSuccess(callable $callback)` on `PendingRetry` — callback receives the `RetryResult` after successful execution
- `wasRetried()` on `RetryResult` — returns true when more than one attempt was made
- `totalDuration()` on `RetryResult` — returns total elapsed time across all attempts in milliseconds

## [1.1.1] - 2026-03-17

### Changed
- Standardized package metadata, README structure, and CI workflow per package guide

## [1.1.0] - 2026-03-16

### Added
- `shouldRetry(callable)` for predicate-based retry decisions
- `retryOnlyOn(string ...$exceptionClasses)` for exception type filtering
- `getAttempts()` to retrieve total attempt count after execution

## [1.0.2] - 2026-03-16

### Changed
- Standardize composer.json: add type, homepage, scripts

## [1.0.1] - 2026-03-15

### Changed
- Standardize README badges

## [1.0.0] - 2026-03-15

### Added
- Initial release
- Fluent retry builder with `Retry::times()` and `Retry::forever()`
- Exponential, linear, and constant backoff strategies
- Jitter support for randomized delays
- Exception filtering with `onlyIf()` and `except()`
- Total time budget with `maxDuration()`
- Before/after retry callbacks
- `RetryResult` value object with attempt tracking
