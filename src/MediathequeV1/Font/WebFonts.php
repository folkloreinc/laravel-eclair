<?php

namespace Folklore\Eclair\MediathequeV1\Font;

use Folklore\Eclair\Data\Job as EclairJob;
use Folklore\Eclair\MediathequeV1\Job;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

/**
 * The mediatheque's `Font\WebFonts` job on Eclair: WOFF2 and WOFF, by format, so their handles
 * stay `woff2` and `woff`. Eclair writes no EOT or SVG fonts: those formats are left out.
 */
class WebFonts extends Job
{
    private const FORMATS = ['woff2', 'woff'];

    protected function tasks(): array
    {
        return ['webfonts' => ['op' => 'font.convert', 'options' => ['formats' => $this->formats()]]];
    }

    protected function files(EclairJob $job): FileContract|array|null
    {
        $files = [];
        foreach ($this->outputs($job, 'webfonts') as $output) {
            $format = strtolower(pathinfo($output->path, PATHINFO_EXTENSION));
            if (in_array($format, self::FORMATS, true)) {
                $files[$format] = $this->makeFile($output, 'font');
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function formats(): array
    {
        $formats = array_values(array_intersect(self::FORMATS, (array) $this->option('formats', self::FORMATS)));

        return $formats === [] ? self::FORMATS : $formats;
    }
}
