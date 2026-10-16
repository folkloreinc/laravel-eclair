# AGENTS.md

`folklore/laravel-eclair` is the Laravel client of [Eclair](https://github.com/urbania-media/eclair), a self-hosted media processing server. It sends jobs to Eclair's API, receives its webhooks, serves the `http` storages of the application, and provides the `eclair` transcoder of [`laravel-mediatheque`](https://github.com/folkloreinc/laravel-mediatheque) 2.

**Language.** This is a public repository. Everything written here is in **English**: code, comments, docs, commit messages, issues and their comments, pull requests and reviews. Never copy private details from the applications that use this package (product plans, infrastructure, hostnames, internal docs, client names). Report security problems privately through a GitHub security advisory, never in a public issue, PR or commit message.

## Status

In development, before 1.0. `main` is the development branch.

- **Plan:** [#1](https://github.com/folkloreinc/laravel-eclair/issues/1). Each item becomes a sub-issue of #1 when work on it starts. Eclair's own plan is [urbania-media/eclair#1](https://github.com/urbania-media/eclair/issues/1).
- **Architecture:** [`docs/architecture.md`](docs/architecture.md) and [`docs/decisions/`](docs/decisions/README.md). Eclair's side of the integration is [`docs/mediatheque.md`](https://github.com/urbania-media/eclair/blob/main/docs/mediatheque.md) in its repository.

## Rules

1. **The protocol is the contract.** The package follows the protocol published in [`@eclair-js/protocol`](https://github.com/urbania-media/eclair/tree/main/packages/protocol), copied in `tests/protocol`. When the package needs something the protocol doesn't have, change the protocol first, in Eclair. Ignore unknown fields in responses and webhooks: version 1 of the protocol only grows.
2. **Secure by default.** Webhooks and storage requests are verified before anything else, with a constant-time comparison and a timestamp tolerance. The storage endpoint is off by default and only serves the storages marked `serve`. Never log a key, a secret or a signed URL.
3. **No static mutable state.** Settings come from config, read when they are used, and services from the container.
4. **Public API.** The facade's methods, the resource classes (`Jobs`, `Presets`, `Uploads`), the data classes, the events, the exceptions, the fake and the config keys are public. Mark everything else `@internal` or make it `final`.
5. **`laravel-mediatheque` stays optional.** It is a suggested dependency: the client works without it, and the transcoder only loads when the mediatheque is installed.

## Style

- PHP 8.3+ and Laravel 12+ ([decision 0001](docs/decisions/0001-supported-versions.md)). Don't use language features newer than PHP 8.3.
- Typehint parameters, return values and properties.
- Format with [Laravel Pint](https://laravel.com/docs/pint) (`laravel` preset): run `composer format` before committing. CI runs `composer lint`.
- Static analysis runs with [Larastan](https://github.com/larastan/larastan) on `src` and `tests`: `composer analyse`. There is no baseline: fix errors, never ignore them.

## Tests

```bash
composer install
composer test
```

- PHPUnit with Orchestra Testbench.
- **Protocol tests.** Requests the client sends and every answer of `EclairFake` are checked against the protocol's JSON Schema (`tests/protocol/eclair.schema.json`, at the Eclair commit in `tests/protocol/VERSION`). Refresh it with `tests/protocol/update.sh <eclair checkout>`.
- **Every change comes with tests.** A bug fix adds a regression test that fails without the fix.
- Before pushing, run `composer lint`, `composer analyse` and `composer test`, and say in the PR what you ran.

## Workflow

- Branch from `main`: `feature/<issue>-<slug>`, `fix/<issue>-<slug>`, `docs/…`, `ci/…`, `refactor/…`, `chore/…`.
- Open a pull request with `Closes #<issue>` or `Part of #<issue>` and the list of checks you ran.
- GitHub Actions (`.github/workflows/ci.yml`) runs Pint, Larastan and the tests (PHP 8.3–8.5 × Laravel 12–13) on every pull request. **Merge only when CI is green.** Never skip, disable or weaken a test to get there.
