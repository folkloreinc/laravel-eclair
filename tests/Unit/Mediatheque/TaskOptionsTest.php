<?php

namespace Folklore\Eclair\Tests\Unit\Mediatheque;

use Folklore\Eclair\Mediatheque\TaskOptions;
use Folklore\Mediatheque\Pipelines\Rendition;
use PHPUnit\Framework\TestCase;

final class TaskOptionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function options(array $definition, ?float $duration = null): array
    {
        return TaskOptions::for(Rendition::fromArray('rendition', $definition), $duration);
    }

    public function test_video_transcode_keeps_the_mediatheques_quality_and_audio_bitrate(): void
    {
        $this->assertSame(['maxWidth' => 1280, 'quality' => 23, 'audioBitrate' => 128], self::options(['op' => 'video.transcode', 'max_width' => 1280, 'preset' => 'slow']));
        $this->assertSame(['codec' => 'vp9', 'quality' => 32, 'audioBitrate' => 96], self::options(['op' => 'video.transcode', 'codec' => 'vp9']));
        $this->assertSame(['audioBitrate' => 192, 'quality' => 20], self::options(['op' => 'video.transcode', 'crf' => 20, 'audio_bitrate' => '192k']));
    }

    public function test_video_transcode_passes_the_passthrough_and_the_maximum_bitrate(): void
    {
        $this->assertSame(
            ['maxWidth' => 1920, 'passthrough' => true, 'maxBitrate' => 5000, 'quality' => 23, 'audioBitrate' => 128],
            self::options(['op' => 'video.transcode', 'max_width' => 1920, 'passthrough' => true, 'max_bitrate' => 5000]),
        );
    }

    public function test_poster_and_thumbnails_are_at_the_mediatheques_times(): void
    {
        $this->assertSame(['time' => 3.0, 'maxWidth' => 1920, 'thumbhash' => true], self::options(['op' => 'video.poster'], 30.0));
        $this->assertSame(['maxWidth' => 640, 'thumbhash' => true], self::options(['op' => 'video.poster', 'max_width' => 640]));
        $this->assertSame(['times' => [5.0, 15.0], 'maxWidth' => 1920, 'thumbhash' => true], self::options(['op' => 'video.thumbnails', 'count' => 2], 20.0));
        $this->assertSame(['count' => 8, 'scenes' => true, 'maxWidth' => 1920, 'thumbhash' => true], self::options(['op' => 'video.thumbnails', 'count' => 8, 'scenes' => true], 20.0));
    }

    public function test_audio_and_images(): void
    {
        $this->assertSame(['codec' => 'mp3'], self::options(['op' => 'audio.transcode']));
        $this->assertSame(['codec' => 'aac', 'bitrate' => 128], self::options(['op' => 'audio.transcode', 'codec' => 'aac']));
        $this->assertSame(
            ['color' => 'ff0000', 'backgroundColor' => 'ffffff', 'data' => false, 'width' => 1200, 'height' => 240, 'thumbhash' => true],
            self::options(['op' => 'audio.waveform', 'color' => '#ff0000', 'background_color' => '#ffffff']),
        );
        $this->assertSame(['format' => 'jpeg', 'quality' => 85, 'thumbhash' => true], self::options(['op' => 'image.convert']));
    }

    public function test_the_operations_that_write_images_ask_for_a_thumbhash_unless_turned_off(): void
    {
        $this->assertSame(['thumbhash' => true], self::options(['op' => 'document.thumbnails']));
        $this->assertSame(['sizes' => [['width' => 320]], 'thumbhash' => true], self::options(['op' => 'image.thumbnail', 'sizes' => [['width' => 320]]]));
        $this->assertSame(['thumbhash' => false, 'format' => 'jpeg', 'quality' => 85], self::options(['op' => 'image.convert', 'thumbhash' => false]));
        $this->assertArrayNotHasKey('thumbhash', self::options(['op' => 'video.transcode']));
        $this->assertArrayNotHasKey('thumbhash', self::options(['op' => 'image.palette']));
    }

    public function test_other_operations_only_get_camel_case_names(): void
    {
        $this->assertSame(
            ['renditions' => [['maxHeight' => 720]], 'segmentDuration' => 6],
            self::options(['op' => 'video.hls', 'renditions' => [['max_height' => 720]], 'segment_duration' => 6]),
        );
    }
}
