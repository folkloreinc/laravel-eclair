# Architecture

`laravel-eclair` connects a Laravel application to an [Eclair](https://github.com/urbania-media/eclair) server. Eclair runs pipelines of operations (transcoding, thumbnails, metadata, transcription…) on files read from and written to the application's storage, and calls the application back when a job ends. The package covers both directions.

```
Application ──── Eclair::jobs()->create() ─────────▶ Eclair API ──▶ workers
     ▲                                                                │
     │  POST /eclair/webhook (signed)                                  │ read inputs, write outputs
     └─────────── WebhookController ◀── job.completed ─────────────────┤
                    └─▶ events: JobCompleted, JobFailed…               │
                                                                       ▼
                 StorageController ◀── GET / PUT /eclair/storages ── http storage
                 (a Laravel disk)                                  (or an s3 bucket,
                                                                   or Eclair's temporary storage)
```

## Pieces

| Piece                                    | Role                                                                                                          |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `Eclair` (facade `Eclair`)               | The client: `jobs()`, `presets()`, `uploads()`, `probe()`, `operations()`, the disk ↔ storage map, downloads    |
| `Jobs`, `Presets`, `Uploads`             | The resources of the `/v1` API                                                                                |
| `Data\*`                                 | Read-only views of the protocol's objects (`Job`, `Task`, `OutputFile`, `Upload`, `ProbeResult`), and enums    |
| `Client` (internal)                      | Sends requests with Laravel's HTTP client, adds the key, turns errors into `RequestFailed` or `ConnectionFailed` |
| `WebhookController`                      | Verifies, deduplicates and dispatches webhooks                                                                |
| `StorageController`                      | The endpoint of the `http` storages served by the application                                                 |
| `Support\Signature`                      | Eclair's signatures: `t={timestamp},v1={hmac}` over `{timestamp}.{payload}`                                   |
| `Testing\EclairFake`                     | An in-memory Eclair behind Laravel's HTTP client ([decision 0003](decisions/0003-fake-behind-the-http-client.md)) |
| `Console\StatusCommand`                  | `eclair:status`                                                                                               |

## Requests

Requests are arrays of the protocol, not builders: a job request is the JSON body of `POST /v1/jobs`. The protocol is documented and validated by Eclair, and new fields reach applications without a new release of this package. `Eclair::input()` and `Eclair::output()` translate a Laravel disk and a path into a storage of the project.

Responses become data classes that read what the package needs and keep the whole response in `toArray()`. Unknown fields are kept, and an unknown error code reads as `operation_failed`, as the protocol asks of clients.

## Webhooks

Eclair sends the whole job with every webhook, retries deliveries for 24 hours and can deliver one more than once, or deliver an older snapshot after a newer one. The controller:

1. checks the signature (HMAC-SHA256 with the project's webhook secret) and the timestamp (five minutes by default), before reading anything else;
2. takes a cache lock on the delivery id (`Eclair-Delivery`), and answers `409` when another request holds it, so Eclair tries again later;
3. ignores a delivery it has already handled;
4. dispatches `WebhookReceived`, then the event of the delivery;
5. remembers the delivery for 48 hours, once every listener has returned. A listener that throws leaves it unhandled: the request fails and Eclair delivers it again.

Listeners still see snapshots out of order: they compare a job's state with theirs before they overwrite it. The `eclair` transcoder of the mediatheque does that through the mediatheque's `Recorder`, which refuses to move a rendition back to an earlier status.

## Storages

Eclair reads and writes files on the project's storages, each registered in Eclair by its operator. `eclair.storages` maps each of them to a Laravel disk, with the same paths on both sides:

- an `s3` storage reads and writes the disk's bucket directly;
- an `http` storage calls the application's endpoint, which serves the disk: `GET` for inputs, `PUT` for outputs. Each request is signed over the path and the SHA-256 of the body. A `PUT` body goes to a temporary file, is hashed on the way, and reaches the disk only once the signature matches;
- the temporary storage (`eclair`) keeps outputs for a while, with signed URLs: `Eclair::download()` and `downloadFiles()` copy them to a disk.

## The mediatheque transcoder (planned)

`laravel-mediatheque` 2 runs its pipelines through a `Transcoder` contract, and probes media through a `Prober` contract (its [decisions 0005 and 0012](https://github.com/folkloreinc/laravel-mediatheque/tree/v2.x/docs/decisions)). The `eclair` transcoder will send a whole pipeline run as one job, and turn its webhooks into `Recorder` calls; the prober will use `Eclair::probe()`. Both will be built on this client once the contracts exist on the mediatheque's `v2.x` branch. Eclair's side of the mapping is in [`docs/mediatheque.md`](https://github.com/urbania-media/eclair/blob/main/docs/mediatheque.md).
