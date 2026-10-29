# 0004. The mediatheque driver translates renditions

Status: accepted (2026-10-29)

## Context

`laravel-mediatheque` 2 declares renditions in Eclair's vocabulary: `video.transcode`, `video.poster`, `audio.waveform`… ([its decision 0005](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/decisions/0005-asynchronous-transcoders.md)). Its `ffmpeg` driver runs them in the application's queue, and an application switches a pipeline to Eclair with its `transcoder` key.

The two sides still differ:

- the mediatheque's options are snake_case (`max_width`), Eclair's are camelCase and refuse unknown ones;
- some defaults differ: the waveform is 240 pixels high on one side and 400 on the other, Eclair converts images to WebP and the mediatheque to JPEG, the thumbnails and the poster are taken at other times, `crf` is `quality` with other values, a bitrate is `'128k'` or `128`;
- the mediatheque checks `when` against the probe of the original, while Eclair checks `kind` and `mime` against each task's own input, and durations only against a probe task of the job;
- Eclair writes files without an ACL.

## Decision

- **Options are translated.** Names become camelCase, and the `ffmpeg` driver's defaults are sent explicitly where Eclair's differ, computed from the media's probe when they depend on its duration. Options the mediatheque doesn't know pass through, so Eclair's own (`scenes`, `in_middle`) can be used. A pipeline gives the same renditions on both drivers.
- **`when` is evaluated at submit time**, against the media's metadata: the media is always probed before its pipeline runs. A rendition that doesn't match is reported `skipped`, with those that read from it, and left out of the job. The job needs no probe task.
- **The run's key is in the job's `metadata`**, and is its idempotency key. Webhooks find the run by it: the mediatheque records the job's id only after `submit()` returns, and a webhook can come first.
- **Files of a public run are made public** after Eclair writes them, before they are recorded.
- **Dimensions, duration and bitrate** of a file come from its task's metadata, until Eclair reports them per file.

## Consequences

- The translation follows the `ffmpeg` driver's operations. A new option or default there needs the same change here, with a test.
- Renditions differ in their files' names: Eclair writes `h264/video.mp4` under the run's prefix where `ffmpeg` writes `h264.mp4`. The mediatheque records whatever path the driver reports.
- When Eclair adds output dimensions, file indexes, skip reasons and visibility to its protocol ([its `docs/mediatheque.md`](https://github.com/urbania-media/eclair/blob/main/docs/mediatheque.md#open-points)), the driver uses them and drops its workarounds.
