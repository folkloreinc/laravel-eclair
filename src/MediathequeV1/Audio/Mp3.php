<?php

namespace Folklore\Eclair\MediathequeV1\Audio;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Eclair\MediathequeV1\Video\Transcode;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Audio\Mp3` job on Eclair, with its options `bitrate` (kbit/s) and `channels`.
 */
class Mp3 extends Job
{
    protected function tasks(): array
    {
        return ['mp3' => ['op' => 'audio.transcode', 'options' => array_filter([
            'codec' => 'mp3',
            'bitrate' => Transcode::positiveInt($this->option('bitrate')),
            // Mono or stereo: Eclair mixes more channels down to stereo
            'channels' => Transcode::positiveInt($this->option('channels')) === null ? null : min(2, (int) $this->option('channels')),
        ], fn ($value) => $value !== null)]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $output = $this->outputs($job, 'mp3')[0] ?? null;
        $duration = $this->dimensions($job->task('mp3'))['duration'];

        return $output === null ? null : $this->makeFile($output, 'audio', ['duration' => $duration]);
    }
}
