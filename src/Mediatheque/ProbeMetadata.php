<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Mediatheque\Metadata\MetadataKey;

/**
 * Eclair's probe metadata, in the mediatheque's terms. Keys become snake_case (`frameRate` →
 * `frame_rate`, `pageCount` → `pages_count`), an embed's `embed` object becomes flat keys
 * (`provider`, `embed_url`, `author_name`…), and:
 *
 * - `width` and `height` are upright: swapped for a file rotated by 90° or 270°;
 * - an image has an EXIF `orientation` (1 to 8) instead of a `rotation`, which the mediatheque
 *   keeps for videos. It is the EXIF tag when the tag agrees with Eclair's `rotation`, which is
 *   what the decoder applies: a HEIC or HEIF image is turned by its container, which libheif
 *   applies, so its EXIF tag (6 for most phone photos) no longer applies once decoded.
 *
 * @internal
 */
final class ProbeMetadata
{
    /** The EXIF orientation of each clockwise rotation, without mirroring. */
    private const array ORIENTATIONS = [0 => 1, 90 => 6, 180 => 3, 270 => 8];

    /** The clockwise rotation of each EXIF orientation, mirrored or not. */
    private const array ROTATIONS = [1 => 0, 2 => 0, 3 => 180, 4 => 180, 5 => 90, 6 => 90, 7 => 270, 8 => 270];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function canonical(array $metadata): array
    {
        $embed = is_array($metadata['embed'] ?? null) ? $metadata['embed'] : null;
        unset($metadata['embed']);
        $metadata = MetadataKey::normalize($metadata);
        if ($embed !== null) {
            $metadata = [...$metadata, ...self::embed($embed)];
        }
        $rotation = $metadata['rotation'] ?? null;
        $rotation = is_int($rotation) ? (($rotation % 360) + 360) % 360 : 0;

        if (in_array($rotation, [90, 270], true) && isset($metadata['width'], $metadata['height'])) {
            [$metadata['width'], $metadata['height']] = [$metadata['height'], $metadata['width']];
        }

        if (($metadata['kind'] ?? null) === 'image') {
            $exif = is_array($metadata['exif'] ?? null) ? $metadata['exif'] : [];
            $orientation = is_numeric($exif['Orientation'] ?? null) ? (int) $exif['Orientation'] : null;
            $metadata['orientation'] = $orientation !== null && (self::ROTATIONS[$orientation] ?? null) === $rotation
                ? $orientation
                : (self::ORIENTATIONS[$rotation] ?? 1);
            unset($metadata['rotation']);
        }

        return $metadata;
    }

    /**
     * The `embed` object of `embed.probe`, in the mediatheque's flat keys for embeds: `provider`,
     * `provider_id`, `provider_url`, `embed_url`, `embed_html`, `author_id`… (its decision 0029).
     *
     * @param  array<array-key, mixed>  $embed
     * @return array<string, mixed>
     */
    private static function embed(array $embed): array
    {
        $author = is_array($embed['author'] ?? null) ? $embed['author'] : [];

        return array_filter([
            'provider' => $embed['provider'] ?? null,
            'provider_id' => $embed['id'] ?? null,
            'provider_url' => $embed['url'] ?? null,
            'embed_url' => $embed['embedUrl'] ?? null,
            'embed_html' => $embed['html'] ?? null,
            'title' => $embed['title'] ?? null,
            'description' => $embed['description'] ?? null,
            'author_id' => $author['id'] ?? null,
            'author_name' => $author['name'] ?? null,
            'author_url' => $author['url'] ?? null,
            'thumbnail_url' => $embed['thumbnailUrl'] ?? null,
            'published_at' => $embed['publishedAt'] ?? null,
            'embeddable' => $embed['embeddable'] ?? null,
            'privacy' => $embed['privacy'] ?? null,
            'live' => $embed['live'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
