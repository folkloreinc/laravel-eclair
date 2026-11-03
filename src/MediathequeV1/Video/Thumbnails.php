<?php

namespace Folklore\Eclair\MediathequeV1\Video;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Video\Thumbnails` job on Eclair, with its options `count` and `in_middle`:
 * one image without `count`, else a list, so their handles stay `thumbnails` or `thumbnails.0`…
 */
class Thumbnails extends Job
{
    protected function tasks(): array
    {
        $count = $this->count();

        return ['thumbnails' => ['op' => 'video.thumbnails', 'options' => [
            'count' => $count ?? 1,
            'inMiddle' => (bool) $this->option('in_middle', false),
        ]]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $dimensions = $this->dimensions($job->task('thumbnails'));
        unset($dimensions['duration']);
        $files = array_map(
            fn ($output) => $this->makeFile($output, 'image', $dimensions),
            $this->outputs($job, 'thumbnails'),
        );

        return $this->count() === null ? ($files[0] ?? null) : $files;
    }

    private function count(): ?int
    {
        $count = $this->option('count');

        return is_numeric($count) && (int) $count > 0 ? (int) $count : null;
    }
}
