# Laravel Eclair

The Laravel client of [Eclair](https://github.com/urbania-media/eclair), a self-hosted server that converts media and extracts their metadata. Send jobs to Eclair, receive its webhooks as events, and let it read and write your files, on S3 or on your own disks.

- A client of Eclair's `/v1` API: jobs, probes, uploads, presets and operations.
- A webhook route that checks signatures, handles each delivery once and dispatches events.
- An endpoint for `http` storages, so Eclair can read inputs from and write outputs to a local disk.
- Downloads of outputs from Eclair's temporary storage to a disk.
- `Eclair::fake()`, an in-memory Eclair for your tests.
- `php artisan eclair:status`, to check the configuration.

The `eclair` transcoder and prober of [`laravel-mediatheque`](https://github.com/folkloreinc/laravel-mediatheque) 2 come with the mediatheque's contracts (see [#1](https://github.com/folkloreinc/laravel-eclair/issues/1)).

**Status:** in development, before 1.0. Requires PHP 8.3+ and Laravel 12+.

## Installation

```bash
composer require folklore/laravel-eclair
php artisan vendor:publish --tag=eclair-config
```

```dotenv
ECLAIR_URL=https://eclair.example.com
ECLAIR_KEY=ek_...
ECLAIR_WEBHOOK_SECRET=whsec_...
```

The key and the webhook secret are the project's, created by Eclair's operator (`eclair projects:create`, `eclair keys:create` and `eclair storages:set`).

Then map the project's storages in Eclair to your disks, in `config/eclair.php`:

```php
'storages' => [
    'media' => ['disk' => 's3'],                    // an s3 storage on the same bucket
    'files' => ['disk' => 'local', 'serve' => true], // an http storage served by this app
],
```

Paths are the same on both sides. An `s3` storage in Eclair has the same bucket as the disk, and its prefix is the disk's root.

```bash
php artisan eclair:status
```

## Jobs

A job runs named tasks on one input and writes their outputs. The request is the protocol's ([`POST /v1/jobs`](https://github.com/urbania-media/eclair/blob/main/docs/protocol.md#post-v1jobs)):

```php
use Folklore\Eclair\Facades\Eclair;

$job = Eclair::jobs()->create([
    'input' => Eclair::input('s3', 'uploads/interview.mov'),
    'output' => Eclair::output('s3', 'videos/42/'),
    'preset' => 'mediatheque/video',
    'tasks' => [
        'poster' => ['op' => 'video.poster', 'from' => 'h264'],
    ],
    'metadata' => ['mediaId' => 42],
], idempotencyKey: 'media-42-v1');

$job->id;      // job_01JA2Y8C6W4N5V7TQ3H9XK0B2M
$job->status;  // JobStatus::Pending
```

With an idempotency key, a retry of the same request returns the first job: retry freely after an error.

```php
$job = Eclair::jobs()->find($id);

$job->ended();                         // completed, failed or canceled
$job->task('h264')->status;            // TaskStatus::Completed
$job->task('h264')->file()->path;      // videos/42/h264.mp4
$job->task('probe')->metadata;         // ['kind' => 'video', 'duration' => 184.2, …]
$job->task('hls')->error?->code;       // ErrorCode::DependencyFailed
$job->toArray();                       // the whole response

Eclair::jobs()->list(['status' => 'failed', 'metadata' => ['mediaId' => 42]]);
Eclair::jobs()->all(['createdAfter' => now()->subDay()]); // a LazyCollection over every page
Eclair::jobs()->cancel($id);
Eclair::jobs()->retry($id);
Eclair::jobs()->files($id);           // every file, segments included
```

Set `ECLAIR_WEBHOOK_URL` to send a webhook URL with every job. Without it, Eclair calls the project's webhook.

## Probe

The metadata of a file, in the response:

```php
$result = Eclair::probe(Eclair::input('s3', 'uploads/interview.mov'), timeout: 10);
$result = Eclair::probeFile($request->file('video'));

if ($result->pending()) {
    // Longer than the timeout: the metadata comes with this job and its webhook.
    $result->job->id;
} else {
    $result->metadata; // ['kind' => 'video', 'mime' => 'video/quicktime', 'width' => 1920, …]
}
```

## Uploads

For files Eclair can't read, send them to its temporary storage:

```php
$upload = Eclair::uploads()->create($request->file('video'), probe: true);

Eclair::jobs()->create(['input' => $upload->input(), 'preset' => 'web-video', 'output' => ['storage' => 'eclair']]);
```

Files are sent in one request, within the project's direct upload limit.

## Webhooks

Eclair calls `POST /eclair/webhook` when a job ends. The route checks the signature with `ECLAIR_WEBHOOK_SECRET`, refuses old timestamps, handles each delivery once, and dispatches:

| Event                                | When                                                                          |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| `Folklore\Eclair\Events\JobCompleted` | `job.completed`                                                               |
| `Folklore\Eclair\Events\JobFailed`    | `job.failed`                                                                  |
| `Folklore\Eclair\Events\JobCanceled`  | `job.canceled`                                                                |
| `Folklore\Eclair\Events\TaskCompleted` | `task.completed`, for projects that subscribe to it                          |
| `Folklore\Eclair\Events\WebhookReceived` | Every verified webhook, first, including events this version doesn't know |

```php
use Folklore\Eclair\Events\JobCompleted;

Event::listen(function (JobCompleted $event) {
    $media = Media::find($event->job->metadata['mediaId']);
    // …
});
```

A listener that throws makes the request fail, and Eclair delivers the webhook again later, for up to 24 hours. Deliveries are remembered in the cache for 48 hours: use a store that every server shares and that supports locks (`ECLAIR_WEBHOOK_CACHE`). Eclair can still send an older snapshot of a job after a newer one: compare before you overwrite.

The route has no middleware by default (no session, no CSRF). Change its path or middleware in `eclair.webhook`, or turn it off with `eclair.webhook.route`.

## Files on your own disks

Eclair reads inputs and writes outputs on the project's storages. Three ways, depending on where your files are:

- **S3-compatible disks:** an `s3` storage in Eclair on the same bucket. Nothing to serve.
- **Local disks, served:** turn on the storage endpoint (`eclair.storage_endpoint.route`) and mark the storage `serve`. Register an `http` storage in Eclair with the URL `{APP_URL}/eclair/storages/{name}`. Eclair reads with signed `GET` requests and writes with signed `PUT` requests, which the endpoint checks before touching the disk. `max_size` limits a received file.
- **Pull from the temporary storage:** write the outputs to Eclair's temporary storage (`'output' => ['storage' => 'eclair']`), then copy them to a disk:

  ```php
  Event::listen(function (JobCompleted $event) {
      Eclair::downloadFiles($event->job, 'local');                      // every file, at its own path
      Eclair::download($event->job->task('poster')->file(), 'local', 'posters/42.jpg');
  });
  ```

## Testing

`Eclair::fake()` replaces Eclair with an in-memory server. The client sends its requests through Laravel's HTTP client as usual, and the fake answers them as Eclair would, so `Http::assertSent()` and `Http::preventStrayRequests()` keep working.

```php
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Testing\InteractsWithEclair;

class VideoTest extends TestCase
{
    use InteractsWithEclair;

    public function test_a_video_is_transcoded(): void
    {
        $eclair = Eclair::fake()->preset('mediatheque/video', ['h264' => ['op' => 'video.transcode']]);

        $this->post('/videos', ['file' => UploadedFile::fake()->create('a.mov')]);

        $eclair->assertJobCreated(fn (array $request) => $request['preset'] === 'mediatheque/video');
        $job = $eclair->completeJob($eclair->lastJob(), [
            'h264' => ['files' => [['name' => 'h264', 'path' => 'videos/1/h264.mp4', 'mime' => 'video/mp4', 'size' => 1024]]],
        ]);
        $this->postEclairWebhook($job)->assertNoContent();

        // …assert what your listeners did
    }
}
```

- Jobs stay pending until the test calls `completeJob()` or `failJob()`. `failJob($job, 'h264', 'timeout')` also fails the tasks that depend on it with `dependency_failed`.
- A file with `contents` in `completeJob()` is in the temporary storage: `Eclair::download()` reads it.
- `probeReturns($metadata)` sets what probes return, and `probeReturns([], pending: true)` makes them answer with a job.
- Assertions: `assertJobCreated()`, `assertJobNotCreated()`, `assertNothingCreated()`, `assertJobCount()`, `assertJobCanceled()`, `assertProbed()`, `assertUploaded()`, `assertPresetSaved()`.
- `postEclairWebhook($job, $event = null, $task = null)` sends a signed webhook to your route.

## Versions

| laravel-eclair | Eclair protocol | PHP  | Laravel |
| -------------- | --------------- | ---- | ------- |
| main (0.x)     | `/v1`           | 8.3+ | 12, 13  |

## License

MIT. See [LICENSE](LICENSE).
