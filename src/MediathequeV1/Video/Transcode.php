<?php

namespace Folklore\Eclair\MediathequeV1\Video;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's ffmpeg video jobs (`H264`, `WebM`, `HEVC`) on Eclair, with their options:
 * `max_width` and `max_height` (when the video is larger than both, see `Job::maxSize()`),
 * `quality` (a CRF), `kilo_bitrate` (a cap, 0 for none) and
 * `audio_kilo_bitrate`.
 */
abstract class Transcode extends Job
{
    /** Eclair's codec. */
    abstract protected function codec(): string;

    protected function tasks(): array
    {
        $options = array_filter([
            'codec' => $this->codec(),
            ...$this->maxSize(),
            'quality' => self::positiveInt($this->option('quality')),
            'maxBitrate' => self::positiveInt($this->option('kilo_bitrate')),
            'audioBitrate' => self::positiveInt($this->option('audio_kilo_bitrate')),
        ], fn ($value) => $value !== null);

        return ['video' => ['op' => 'video.transcode', 'options' => $options]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $output = $this->outputs($job, 'video')[0] ?? null;

        return $output === null ? null : $this->makeFile($output, 'video', $this->dimensions($job->task('video')));
    }

    /** @internal */
    public static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
