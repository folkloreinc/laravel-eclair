<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Mediatheque\Metadata\MetadataKey;

/**
 * Eclair's probe metadata, in the mediatheque's terms. Keys become snake_case (`frameRate` →
 * `frame_rate`, `pageCount` → `pages_count`), and:
 *
 * - `width` and `height` are upright: swapped for a file rotated by 90° or 270°;
 * - an image has an EXIF `orientation` (1 to 8) instead of a `rotation`, which the mediatheque
 *   keeps for videos.
 *
 * @internal
 */
final class ProbeMetadata
{
    /** The EXIF orientation of each clockwise rotation, without mirroring. */
    private const array ORIENTATIONS = [0 => 1, 90 => 6, 180 => 3, 270 => 8];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function canonical(array $metadata): array
    {
        $metadata = MetadataKey::normalize($metadata);
        $rotation = $metadata['rotation'] ?? null;
        $rotation = is_int($rotation) ? (($rotation % 360) + 360) % 360 : 0;

        if (in_array($rotation, [90, 270], true) && isset($metadata['width'], $metadata['height'])) {
            [$metadata['width'], $metadata['height']] = [$metadata['height'], $metadata['width']];
        }

        if (($metadata['kind'] ?? null) === 'image') {
            $exif = is_array($metadata['exif'] ?? null) ? $metadata['exif'] : [];
            $orientation = $exif['Orientation'] ?? null;
            $metadata['orientation'] = is_numeric($orientation) && (int) $orientation >= 1 && (int) $orientation <= 8
                ? (int) $orientation
                : (self::ORIENTATIONS[$rotation] ?? 1);
            unset($metadata['rotation']);
        }

        return $metadata;
    }
}
