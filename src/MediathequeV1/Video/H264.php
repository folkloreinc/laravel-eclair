<?php

namespace Folklore\Eclair\MediathequeV1\Video;

/**
 * The mediatheque's `Video\H264` job on Eclair: H.264 in MP4.
 */
class H264 extends Transcode
{
    protected function codec(): string
    {
        return 'h264';
    }
}
