<?php

namespace Folklore\Eclair\Tests\Unit\Mediatheque;

use Folklore\Eclair\Mediatheque\ProbeMetadata;
use PHPUnit\Framework\TestCase;

final class ProbeMetadataTest extends TestCase
{
    public function test_keys_become_the_mediatheques(): void
    {
        $this->assertSame(
            ['kind' => 'document', 'mime' => 'application/pdf', 'pages_count' => 3, 'font_names' => ['A']],
            ProbeMetadata::canonical(['kind' => 'document', 'mime' => 'application/pdf', 'pageCount' => 3, 'fontNames' => ['A']]),
        );
    }

    public function test_a_rotated_video_is_upright(): void
    {
        $metadata = ProbeMetadata::canonical(['kind' => 'video', 'width' => 1920, 'height' => 1080, 'rotation' => 270]);

        $this->assertSame([1080, 1920, 270], [$metadata['width'], $metadata['height'], $metadata['rotation']]);
    }

    public function test_an_image_has_an_exif_orientation(): void
    {
        $this->assertSame(
            ['kind' => 'image', 'width' => 3000, 'height' => 4000, 'exif' => ['Orientation' => '6'], 'orientation' => 6],
            ProbeMetadata::canonical(['kind' => 'image', 'width' => 4000, 'height' => 3000, 'rotation' => 90, 'exif' => ['Orientation' => '6']]),
        );
        $this->assertSame(3, ProbeMetadata::canonical(['kind' => 'image', 'rotation' => 180])['orientation']);
        $this->assertSame(1, ProbeMetadata::canonical(['kind' => 'image'])['orientation']);
    }
}
