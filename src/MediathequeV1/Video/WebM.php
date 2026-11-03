<?php

namespace Folklore\Eclair\MediathequeV1\Video;

/**
 * The mediatheque's `Video\WebM` job on Eclair: VP9 and Opus in WebM (the mediatheque's own writes VP8).
 */
class WebM extends Transcode
{
    protected function codec(): string
    {
        return 'vp9';
    }
}
