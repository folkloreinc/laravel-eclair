# 0001. PHP 8.3+ and Laravel 12+

Status: accepted (2026-10-16)

## Context

The first application on Eclair starts on `laravel-mediatheque` 2, which supports PHP 8.3+ and Laravel 12+. The applications on `laravel-mediatheque` 1.x run older versions, but they will reach Eclair through an opt-in driver in a 1.x minor release, later ([Eclair's decision 0017](https://github.com/urbania-media/eclair/blob/main/docs/decisions/0017-mediatheque-2-transcoder-first.md)).

## Decision

`laravel-eclair` supports PHP 8.3+ and Laravel 12+, the versions of `laravel-mediatheque` 2. CI tests PHP 8.3, 8.4 and 8.5 with Laravel 12 and 13. Larastan analyses the code against PHP 8.3.

## Consequences

- The client can use readonly classes, enums and typed constants.
- When the 1.x driver is built, it may need versions this package doesn't support yet. Widening the range is then a new decision, made with the versions of the applications that need it.
