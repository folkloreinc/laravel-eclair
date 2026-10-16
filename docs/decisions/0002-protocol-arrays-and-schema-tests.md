# 0002. Protocol arrays, checked against its schema

Status: accepted (2026-10-16)

## Context

Eclair's protocol is published as an OpenAPI document and a JSON Schema in `@eclair-js/protocol`. Version 1 only grows: new fields, operations and options. A PHP client can mirror it with builders and classes for every request, or pass the protocol's own objects through.

## Decision

- Requests are arrays of the protocol (a job request is the body of `POST /v1/jobs`). The package adds helpers where Laravel knows something Eclair doesn't, such as the storage of a disk, not a second vocabulary.
- Responses become read-only data classes for what applications use often (jobs, tasks, files, uploads, probe results), which keep the whole response in `toArray()`. Presets and operations stay arrays.
- The protocol's JSON Schema is copied in `tests/protocol`, with the Eclair commit it comes from. Tests check the requests the client sends, and every answer of the fake, against it.

## Consequences

- A new field or operation works without a release of this package.
- A request that doesn't match the protocol fails in Eclair with a `422` and the problem's errors, not in PHP. Applications' own tests catch it through the fake only for what the fake checks.
- Updating the protocol is a pull request that runs `tests/protocol/update.sh` and fixes what the tests find.
