# Changelog

All notable changes to `php-retry` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
