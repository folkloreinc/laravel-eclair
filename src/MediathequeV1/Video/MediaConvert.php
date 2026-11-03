<?php

namespace Folklore\Eclair\MediathequeV1\Video;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Video\MediaConvert` job on Eclair, with its options: `format` (`h264`,
 * `webm`, `h265`), `max_width` and `max_height` (when the video is larger than both, see
 * `Job::maxSize()`) and `bitrate` (kbit/s, a cap). It returns its files
 * by format, as MediaConvert's, so their handles stay `h264`, `webm` and `h265`.
 */
class MediaConvert extends Job
{
    private const CODECS = ['h264' => 'h264', 'webm' => 'vp9', 'h265' => 'h265'];

    protected function tasks(): array
    {
        $tasks = [];
        foreach ($this->formats() as $format) {
            $tasks[$format] = ['op' => 'video.transcode', 'options' => array_filter([
                'codec' => self::CODECS[$format],
                ...$this->maxSize(),
                'maxBitrate' => Transcode::positiveInt($this->option('bitrate')),
            ], fn ($value) => $value !== null)];
        }

        return $tasks;
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $files = [];
        foreach ($this->formats() as $format) {
            $output = $this->outputs($job, $format)[0] ?? null;
            if ($output !== null) {
                $files[$format] = $this->makeFile($output, 'video', [
                    ...$this->dimensions($job->task($format)),
                    'format' => [$format, 'string'],
                ]);
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function formats(): array
    {
        $formats = (array) $this->option('format', ['h264']);

        return array_values(array_filter($formats, fn ($format) => is_string($format) && isset(self::CODECS[$format])));
    }
}
