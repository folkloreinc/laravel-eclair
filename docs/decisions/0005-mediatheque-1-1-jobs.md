# 0005. Eclair jobs for laravel-mediatheque 1.1 wait in the queue

Status: accepted (2026-11-03)

## Context

Many applications still run `laravel-mediatheque` 1.1. Their pipelines are lists of job classes (`Video\H264`, `Video\Thumbnails`…), each run by the pipeline's queued job (`RunPipelineJob`), which attaches the files the job returns under the job's handle. The web servers run ffmpeg, ImageMagick and audiowaveform for them, and some applications pay AWS MediaConvert for their transcodes.

1.1 has no transcoder contract like 2.x's. It does already have a remote transcoder: its `Video\MediaConvert` job creates the AWS job, checks it every ten seconds in the queued job, then attaches the files AWS wrote to the bucket.

## Decision

- **One Eclair job class per 1.1 job**, in `Folklore\Eclair\MediathequeV1`, with the same options and the same return shape: one file, files by format (`MediaConvert`, `WebFonts`), or files by index (thumbnails, pages). An application swaps the class in its pipeline's `job`. Nothing changes in `laravel-mediatheque` 1.1.
- **They wait in the queue, as `MediaConvert` does.** A job sends one Eclair job and reads it every `poll_interval` seconds until it ends.
    - No webhook route is needed, the job's result is the pipeline job's result, and a failure fails it the usual way.
    - The worker waits idle, without the CPU a local transcode takes.
    - Past `timeout`, the Eclair job is canceled and the pipeline job fails.
- **Files are moved to the mediatheque's paths.** Eclair writes under `eclair/` on the original's disk. The job then moves each file with the mediatheque's `setFileFromSource()`, as `MediaConvert` does from its `converted/` folder, so the files land where the mediatheque's own jobs would put them.
- **Only `filesystem` sources** whose disk is mapped to an Eclair storage. A local source is refused with a clear error. Moving those applications means a storage Eclair can reach first (an `s3` bucket, or an `http` storage).
- **The same supported versions** (decision 0001): PHP 8.3+ and Laravel 12+. Applications on older versions upgrade first.
- **Tested with laravel-mediatheque 1.1** in a CI job of its own (`composer test:v1`, `composer analyse:v1`), since 1.1 and 2.x can't be installed together.

## Consequences

- A pipeline moves to Eclair one job at a time, and back by restoring the class.
- A job whose pipeline runs with `'queue' => false` waits for Eclair during the request: such jobs should be queued when they move.
- The metadata services of 1.1 (durations, dimensions, palettes at ingest) still run on the application's server. Binding them to Eclair's probe is a later step.
