<?php

namespace Folklore\Eclair\MediathequeV1\Document;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Document\Thumbnails` job on Eclair, with its options `count` (a number, or
 * `all`), `resolution`, `quality` and `format`. Pages are listed by index, as the mediatheque's.
 */
class Thumbnails extends Job
{
    protected function tasks(): array
    {
        $count = $this->option('count', 'all');
        $format = $this->option('format', 'jpeg');

        return ['pages' => ['op' => 'document.thumbnails', 'options' => array_filter([
            'count' => is_numeric($count) && (int) $count > 0 ? (int) $count : null,
            'resolution' => (int) $this->option('resolution', 150),
            'quality' => min(100, max(1, (int) $this->option('quality', 100))),
            'format' => $format === 'jpg' ? 'jpeg' : $format,
        ], fn ($value) => $value !== null)]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $dimensions = $this->dimensions($job->task('pages'));
        unset($dimensions['duration']);

        return array_map(fn ($output) => $this->makeFile($output, 'image', $dimensions), $this->outputs($job, 'pages'));
    }
}
