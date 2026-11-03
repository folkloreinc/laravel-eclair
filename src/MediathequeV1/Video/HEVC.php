<?php

namespace Folklore\Eclair\MediathequeV1\Video;

/**
 * The mediatheque's `Video\HEVC` job on Eclair: H.265 in MP4.
 */
class HEVC extends Transcode
{
    protected function codec(): string
    {
        return 'h265';
    }
}
