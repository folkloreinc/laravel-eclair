# 0003. The fake answers behind the HTTP client

Status: accepted (2026-10-16)

## Context

Applications need to test what they send to Eclair and what they do with its webhooks, without a server. A fake can replace the client's classes, or answer the client's HTTP requests.

## Decision

`Eclair::fake()` registers an in-memory Eclair as a stub of Laravel's HTTP client for the configured URL, and swaps the facade for `EclairFake`, a subclass of the client with test helpers and assertions. The client's code runs as it does in production: the same requests, the same parsing of responses and errors.

The fake keeps jobs, presets, uploads and temporary files in memory. Jobs stay pending until a test completes or fails them. `InteractsWithEclair::postEclairWebhook()` sends the job to the application's route as a signed webhook.

## Consequences

- `Http::assertSent()`, `Http::recorded()` and `Http::preventStrayRequests()` keep working with the fake.
- Every answer of the fake is checked against the protocol in this package's tests ([0002](0002-protocol-arrays-and-schema-tests.md)), so its answers have the shape of Eclair's.
- The fake is not Eclair: it doesn't run operations or validate their options, and it accepts any operation name. Pipelines are tested against a real server.
