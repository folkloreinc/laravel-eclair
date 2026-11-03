<?php

namespace Folklore\Eclair\MediathequeV1\Audio;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Audio\Thumbnails` job on Eclair: the waveform image, with its options
 * `width`, `height`, `zoom`, `axis_label`, `color`, `background_color`, `border_color` and
 * `axis_label_color`.
 */
class Thumbnails extends Job
{
    protected function tasks(): array
    {
        $color = fn (string $name) => is_string($this->option($name)) && $this->option($name) !== '' ? ltrim($this->option($name), '#') : null;
        $zoom = $this->option('zoom', 600);

        return ['waveform' => ['op' => 'audio.waveform', 'options' => array_filter([
            'data' => false,
            'width' => (int) $this->option('width', 1200),
            'height' => (int) $this->option('height', 400),
            'zoom' => is_numeric($zoom) ? (int) $zoom : 'auto',
            'axisLabels' => (bool) $this->option('axis_label', false),
            'color' => $color('color') ?? '000000',
            'backgroundColor' => $color('background_color') ?? 'FFFFFF00',
            'borderColor' => $color('border_color'),
            'axisLabelColor' => $color('axis_label_color'),
        ], fn ($value) => $value !== null)]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $output = $this->outputs($job, 'waveform')[0] ?? null;

        return $output === null ? null : $this->makeFile($output, 'image', [
            'width' => [(int) $this->option('width', 1200), 'integer'],
            'height' => [(int) $this->option('height', 400), 'integer'],
        ]);
    }
}
