<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Mediatheque\Pipelines\Rendition;

/**
 * The options of an Eclair task, from a rendition of the mediatheque.
 *
 * Renditions use the mediatheque's names (`max_width`, `audio_bitrate: '128k'`) and its `ffmpeg`
 * driver's defaults. Eclair takes camelCase names, refuses unknown options, and some of its
 * defaults differ. This passes the mediatheque's defaults explicitly where they differ, so a
 * pipeline gives the same renditions on both transcoders: switching is configuration.
 *
 * Options the mediatheque doesn't know (Eclair's own, such as `in_middle` or `scenes`) are passed
 * through, in camelCase.
 *
 * @internal
 */
final class TaskOptions
{
    /**
     * @param  float|null  $duration  The duration of the input, in seconds, from its probe
     * @return array<string, mixed>
     */
    public static function for(Rendition $rendition, ?float $duration = null): array
    {
        $options = $rendition->options;

        $options = match ($rendition->operation) {
            'video.transcode' => self::videoTranscode($options),
            'video.poster' => self::videoPoster($options, $duration),
            'video.thumbnails' => self::videoThumbnails($options, $duration),
            'audio.transcode' => self::audioTranscode($options),
            'audio.waveform' => self::audioWaveform($options),
            'image.convert' => self::imageConvert($options),
            default => $options,
        };

        return self::camelKeys($options);
    }

    /**
     * H.264 with a CRF of 23 or VP9 with 32, and AAC or Opus audio at 128 or 96 kbit/s. `preset`
     * only exists in ffmpeg's x264: Eclair uses `medium`, the driver's default.
     *
     * Both drivers share the rest of H.264's defaults (High profile, a keyframe every 2 seconds, a
     * maximum bitrate from the output's size) and the options `max_bitrate` and `passthrough`,
     * which pass as they are. Each reports whether it copied or encoded the video and the audio
     * in the metadata of the file (`video` and `audio`).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function videoTranscode(array $options): array
    {
        $vp9 = ($options['codec'] ?? 'h264') === 'vp9';
        $options['quality'] = $options['quality'] ?? $options['crf'] ?? ($vp9 ? 32 : 23);
        $options['audio_bitrate'] = self::kilobits($options['audio_bitrate'] ?? null) ?? ($vp9 ? 96 : 128);
        unset($options['crf'], $options['preset']);

        return $options;
    }

    /**
     * The frame at `min(10, duration / 10)`, at most 1920 pixels wide.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function videoPoster(array $options, ?float $duration): array
    {
        if (! isset($options['time']) && $duration !== null && $duration > 0) {
            $options['time'] = round(min(10.0, $duration / 10), 3);
        }
        $options['max_width'] ??= 1920;

        return $options;
    }

    /**
     * `count` frames (5) at the middle of equal parts of the video, at most 1920 pixels wide.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function videoThumbnails(array $options, ?float $duration): array
    {
        $count = is_int($options['count'] ?? null) ? $options['count'] : 5;
        $chosen = isset($options['times']) || isset($options['interval']) || ($options['scenes'] ?? false) === true;
        if (! $chosen && $duration !== null && $duration > 0) {
            $options['times'] = array_map(
                fn (int $index) => round($duration * ($index + 0.5) / $count, 3),
                range(0, $count - 1),
            );
            unset($options['count']);
        }
        $options['max_width'] ??= 1920;

        return $options;
    }

    /**
     * MP3 or AAC, with the bitrate in kbit/s.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function audioTranscode(array $options): array
    {
        $options['codec'] ??= 'mp3';
        if (isset($options['bitrate'])) {
            $options['bitrate'] = self::kilobits($options['bitrate']) ?? $options['bitrate'];
        } elseif ($options['codec'] === 'aac') {
            $options['bitrate'] = 128;
        }

        return $options;
    }

    /**
     * An image only, 1200 × 240, black on a transparent background. Colors lose their `#`.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function audioWaveform(array $options): array
    {
        $options['data'] ??= false;
        $options['width'] ??= 1200;
        $options['height'] ??= 240;
        $options['color'] ??= '000000';
        foreach ($options as $key => $value) {
            if (str_ends_with((string) $key, 'color') && is_string($value)) {
                $options[$key] = ltrim($value, '#');
            }
        }

        return $options;
    }

    /**
     * JPEG at quality 85, the same size.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function imageConvert(array $options): array
    {
        $options['format'] ??= 'jpeg';
        $options['quality'] ??= 85;

        return $options;
    }

    /** `'128k'` is 128, `128` stays 128. */
    private static function kilobits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(\d+)k$/i', $value, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function camelKeys(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $key = is_string($key) ? lcfirst(str_replace('_', '', ucwords($key, '_'))) : $key;
            $result[$key] = is_array($value) ? self::camelKeys($value) : $value;
        }

        return $result;
    }
}
