<?php

namespace Folklore\Eclair\Support;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;

/**
 * @internal
 */
final class Body
{
    /**
     * A file to send: its contents, a stream resource, a PSR-7 stream, or a file (`UploadedFile`,
     * `File`, `SplFileInfo`).
     */
    public static function from(mixed $file): StreamInterface|string
    {
        if (is_string($file) || $file instanceof StreamInterface) {
            return $file;
        }
        if (is_resource($file)) {
            return Utils::streamFor($file);
        }
        if ($file instanceof SplFileInfo) {
            $path = $file->getRealPath();
            if ($path === false) {
                throw new InvalidArgumentException(sprintf('Could not read %s.', $file->getPathname()));
            }

            return Utils::streamFor(Utils::tryFopen($path, 'r'));
        }

        throw new InvalidArgumentException('A file is a string, a stream or a file object.');
    }
}
