# Laravel Eclair

The Laravel client of [Eclair](https://github.com/urbania-media/eclair), a self-hosted server that converts media and extracts their metadata. Send jobs to Eclair, receive its webhooks as events, and let it read and write your files, on S3 or on your own disks.

- A client of Eclair's `/v1` API: jobs, probes, uploads, presets and operations.
- A webhook route that checks signatures, handles each delivery once and dispatches events.
- An endpoint for `http` storages, so Eclair can read inputs from and write outputs to a local disk.
- Downloads of outputs from Eclair's temporary storage to a disk.
- `Eclair::fake()`, an in-memory Eclair for your tests.
- `php artisan eclair:status`, to check the configuration.
- The `eclair` transcoder and prober of [`laravel-mediatheque`](https://github.com/folkloreinc/laravel-mediatheque) 2.

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

`checksum: true` adds a SHA-256 of the whole file, and `thumbhash: true` a ThumbHash placeholder of an image.

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

## laravel-mediatheque 2

With `folklore/laravel-mediatheque` 2.0.0-alpha.11 or newer, a pipeline can run on Eclair instead of the application's own ffmpeg. Name the `eclair` transcoder in it:

```php
// config/mediatheque.php
'pipelines' => [
    'video' => [
        'transcoder' => 'eclair',
        'renditions' => [
            'h264' => ['op' => 'video.transcode', 'max_width' => 1920],
            'poster' => ['op' => 'video.poster', 'from' => 'h264', 'role' => 'poster'],
            'thumbnails' => ['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail'],
        ],
    ],
],
```

Renditions stay those of the `ffmpeg` driver: same operations, same options and same defaults, so switching a pipeline is configuration. A run becomes one Eclair job, with a task per rendition, which writes its files under the run's path prefix on the run's disk. The webhooks report each rendition to the mediatheque as it completes.

What it needs:

- The disks of the originals and renditions in `eclair.storages`, mapped to storages of the Eclair project. With an S3-compatible disk, the storage is on the same bucket, with the disk's `root` as prefix.
- The webhook route, reachable by Eclair, and the project's `taskWebhooks` setting on (`eclair projects:update <project> --set taskWebhooks=true`), so each rendition is recorded when it completes instead of when the whole run ends.
- The operations of the server: Eclair's built-in ones. Add the server's others (plugins) to `eclair.mediatheque.operations`.

`ECLAIR_MEDIATHEQUE_PROBER=true` probes the media with Eclair too, so the application's servers need neither ffprobe nor ImageMagick. Images get their ThumbHash placeholder (`thumbhash`), as with the mediatheque's local prober, from an Eclair server that has it (urbania-media/eclair#65). A probe longer than `eclair.mediatheque.probe_timeout` seconds completes with its job's webhook.

How it maps ([decision 0004](docs/decisions/0004-mediatheque-driver.md)):

- Options are renamed for Eclair (`max_width` → `maxWidth`, `crf` → `quality`, `'128k'` → `128`), and the `ffmpeg` driver's defaults are sent where Eclair's differ (the waveform's size, the thumbnails' times, the poster's time…). Options of Eclair's own (`scenes`, `in_middle`…) pass through, and so do `max_bitrate` and `passthrough` of `video.transcode`, which both drivers know: with `passthrough: true`, a web-ready H.264 upload is copied instead of encoded, and the file's metadata says so (`video` and `audio`: `copied` or `encoded`). `passthrough` needs an Eclair whose `video.transcode` knows it.
- A rendition whose `when` doesn't match the probed media is skipped at submit time, with those that read from it.
- Files of a public run are made public once written: Eclair writes them private.
- `cancel()` cancels the job, and `refresh()` reads it again, for a webhook that never came.

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
- `completeTask($job, 'h264', $outputs)` completes one task while the job keeps running, as with `task.completed` webhooks.
- `probeReturns($metadata)` sets what probes return, and `probeReturns([], pending: true)` makes them answer with a job.
- Assertions: `assertJobCreated()`, `assertJobNotCreated()`, `assertNothingCreated()`, `assertJobCount()`, `assertJobCanceled()`, `assertProbed()`, `assertUploaded()`, `assertPresetSaved()`.
- `postEclairWebhook($job, $event = null, $task = null)` sends a signed webhook to your route.

## Versions

| laravel-eclair | Eclair protocol | PHP  | Laravel | laravel-mediatheque  |
| -------------- | --------------- | ---- | ------- | -------------------- |
| main (0.x)     | `/v1`           | 8.3+ | 12, 13  | 2.0.0-alpha.11+ (optional) |

## License

MIT. See [LICENSE](LICENSE).
