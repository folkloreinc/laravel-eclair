<?php

namespace Folklore\Eclair\Tests\MediathequeV1;

use Folklore\Eclair\MediathequeV1\Audio\Mp3;
use Folklore\Eclair\MediathequeV1\Audio\Thumbnails as AudioThumbnails;
use Folklore\Eclair\MediathequeV1\Document\Thumbnails as DocumentThumbnails;
use Folklore\Eclair\MediathequeV1\Font\WebFonts;
use Folklore\Eclair\MediathequeV1\JobFailed;
use Folklore\Eclair\MediathequeV1\Video\H264;
use Folklore\Eclair\MediathequeV1\Video\MediaConvert;
use Folklore\Eclair\MediathequeV1\Video\Thumbnails;
use Folklore\Eclair\Tests\ValidatesProtocol;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

final class JobsTest extends TestCase
{
    use ValidatesProtocol;

    public function test_media_convert_gives_its_files_by_format_on_the_same_disk(): void
    {
        $original = $this->original(metadata: ['width' => 3840, 'height' => 2160]);
        $this->completeWith([
            'h264' => ['files' => ['video.mp4'], 'metadata' => ['width' => 1080, 'height' => 608, 'duration' => 12.5]],
            'webm' => ['files' => ['video.webm'], 'metadata' => ['width' => 1080, 'height' => 608, 'duration' => 12.5]],
        ], polls: 2);

        $files = (new MediaConvert($original, ['format' => ['h264', 'webm'], 'max_width' => 1080, 'max_height' => 1080, 'bitrate' => 2500, 'quality' => 25]))->handle();

        $this->eclair->assertJobCreated(function (array $request) use ($original): bool {
            $this->assertMatchesProtocol('JobRequest', $request);
            $this->assertSame(['storage' => 'media', 'path' => ltrim((string) $original->getAttribute('path'), '/')], $request['input']);
            $this->assertStringStartsWith('eclair/'.$original->getKey().'-mediaconvert-', $request['output']['prefix']);
            $this->assertSame(['op' => 'video.transcode', 'options' => ['codec' => 'h264', 'maxWidth' => 1080, 'maxHeight' => 1080, 'quality' => 25, 'maxBitrate' => 2500]], $request['tasks']['h264']);
            $this->assertSame('vp9', $request['tasks']['webm']['options']['codec']);

            return true;
        });
        $this->assertIsArray($files);
        $this->assertSame(['h264', 'webm'], array_keys($files));
        $this->assertInstanceOf(File::class, $files['h264']);
        $h264 = $files['h264']->fresh();
        $this->assertInstanceOf(File::class, $h264);
        $this->assertSame('video/mp4', $h264->getAttribute('mime'));
        $this->assertSame(1080, $h264->getMetadata('width')?->getValue());
        $this->assertEquals(12.5, $h264->getMetadata('duration')?->getValue());
        Storage::disk('s3')->assertExists(ltrim((string) $h264->getAttribute('path'), '/'));
        $this->assertStringNotContainsString('eclair/', (string) $h264->getAttribute('path'));
    }

    public function test_a_video_is_resized_only_when_larger_than_both_maximums(): void
    {
        $maxSize = function (array $metadata, array $options = []): array {
            $this->completeWith(['h264' => ['files' => ['video.mp4']]]);
            (new MediaConvert($this->original(metadata: $metadata), ['format' => ['h264'], 'max_width' => 1080, 'max_height' => 1080, ...$options]))->handle();
            $options = [];
            $this->eclair->assertJobCreated(function (array $request) use (&$options): bool {
                $options = array_intersect_key($request['tasks']['h264']['options'], ['maxWidth' => true, 'maxHeight' => true]);

                return true;
            });

            return $options;
        };

        // As the mediatheque: a portrait video that fits in the width keeps its size
        $this->assertSame([], $maxSize(['width' => 1080, 'height' => 1920]));
        $this->assertSame([], $maxSize([]));
        $this->assertSame(['maxWidth' => 1080, 'maxHeight' => 1080], $maxSize(['width' => 1440, 'height' => 2560]));
        $this->assertSame(['maxWidth' => 1080, 'maxHeight' => 1080], $maxSize(['width' => 640, 'height' => 360], ['upscale' => true]));
    }

    public function test_files_get_the_visibility_of_their_source_or_disk(): void
    {
        $visibility = function (): string {
            $this->completeWith(['video' => ['files' => ['video.mp4']]]);
            $file = (new H264($this->original()))->handle();
            $this->assertInstanceOf(File::class, $file);

            return Storage::disk('s3')->getVisibility(ltrim((string) $file->getAttribute('path'), '/'));
        };

        $this->assertSame('private', $visibility());

        config(['filesystems.disks.s3.visibility' => 'public']);
        $this->assertSame('public', $visibility());

        config(['mediatheque.sources.cloud.visibility' => 'private']);
        $this->assertSame('private', $visibility());
    }

    public function test_thumbnails_are_listed_by_index_or_one_without_count(): void
    {
        $original = $this->original();
        $this->completeWith(['thumbnails' => ['files' => ['0.jpg', '1.jpg', '2.jpg'], 'metadata' => ['width' => 640, 'height' => 360]]]);

        $files = (new Thumbnails($original, ['count' => 3, 'in_middle' => true]))->handle();

        $this->eclair->assertJobCreated(fn (array $request) => $request['tasks']['thumbnails']['options'] === ['count' => 3, 'inMiddle' => true]);
        $this->assertIsArray($files);
        $this->assertSame([0, 1, 2], array_keys($files));

        $this->completeWith(['thumbnails' => ['files' => ['0.jpg']]]);
        $this->assertInstanceOf(FileContract::class, (new Thumbnails($this->original()))->handle());
    }

    public function test_the_other_jobs_translate_their_options(): void
    {
        $this->completeWith(['video' => ['files' => ['video.mp4']]]);
        (new H264($this->original(metadata: ['width' => 1920, 'height' => 1080]), ['max_width' => 480, 'max_height' => 480, 'quality' => 26, 'kilo_bitrate' => 0]))->handle();
        $this->eclair->assertJobCreated(fn (array $request) => ($request['tasks']['video'] ?? null) === ['op' => 'video.transcode', 'options' => ['codec' => 'h264', 'maxWidth' => 480, 'maxHeight' => 480, 'quality' => 26]]);

        $this->completeWith(['mp3' => ['files' => ['audio.mp3'], 'metadata' => ['duration' => 30]]]);
        $mp3 = (new Mp3($this->original('audio', 'audio/wav', 'wav'), ['bitrate' => 196, 'channels' => 6]))->handle();
        $this->eclair->assertJobCreated(fn (array $request) => ($request['tasks']['mp3']['options'] ?? null) === ['codec' => 'mp3', 'bitrate' => 196, 'channels' => 2]);
        $this->assertInstanceOf(FileContract::class, $mp3);

        $this->completeWith(['waveform' => ['files' => ['waveform.png']]]);
        (new AudioThumbnails($this->original('audio', 'audio/wav', 'wav'), ['zoom' => 600, 'width' => 1200, 'height' => 400, 'background_color' => 'FFFFFF00', 'color' => '#000000']))->handle();
        $this->eclair->assertJobCreated(fn (array $request) => ($request['tasks']['waveform']['options'] ?? null) === ['data' => false, 'width' => 1200, 'height' => 400, 'zoom' => 600, 'axisLabels' => false, 'color' => '000000', 'backgroundColor' => 'FFFFFF00']);

        $this->completeWith(['pages' => ['files' => ['0.jpg', '1.jpg']]]);
        $pages = (new DocumentThumbnails($this->original('document', 'application/pdf', 'pdf'), ['count' => 'all', 'resolution' => 150, 'quality' => 100, 'format' => 'jpeg']))->handle();
        $this->eclair->assertJobCreated(fn (array $request) => ($request['tasks']['pages']['options'] ?? null) === ['resolution' => 150, 'quality' => 100, 'format' => 'jpeg']);
        $this->assertIsArray($pages);
        $this->assertCount(2, $pages);

        $this->completeWith(['webfonts' => ['files' => ['font.woff2', 'font.woff']]]);
        $fonts = (new WebFonts($this->original('font', 'font/ttf', 'ttf'), ['formats' => ['woff', 'woff2', 'eot']]))->handle();
        $this->assertIsArray($fonts);
        $this->assertSame(['woff2', 'woff'], array_keys($fonts));
    }

    public function test_a_failed_job_fails_the_pipeline_job(): void
    {
        $original = $this->original();
        Sleep::fake();
        Sleep::whenFakingSleep(function (): void {
            $job = $this->eclair->lastJob();
            $this->assertNotNull($job);
            $this->eclair->failJob($job, 'h264', 'unsupported_input', 'Not a video.');
        });

        $this->expectException(JobFailed::class);
        $this->expectExceptionMessage('the task "h264" failed (unsupported_input): Not a video.');
        (new MediaConvert($original, ['format' => ['h264']]))->handle();
    }

    public function test_a_job_that_takes_too_long_is_canceled(): void
    {
        config(['eclair.mediatheque_v1.timeout' => 0]);
        $original = $this->original();

        try {
            (new H264($original))->handle();
            $this->fail('No exception');
        } catch (JobFailed $exception) {
            $this->assertStringContainsString('did not end within 0 seconds', $exception->getMessage());
        }
        $job = $this->eclair->lastJob();
        $this->assertNotNull($job);
        $this->eclair->assertJobCanceled($job);
    }

    public function test_a_local_source_is_refused(): void
    {
        config(['mediatheque.sources.public' => ['driver' => 'local', 'path' => sys_get_temp_dir()]]);
        $original = $this->original();
        $original->forceFill(['source' => 'public'])->save();

        $this->expectExceptionMessage('Eclair reads the files of `filesystem` sources only');
        (new H264($original))->handle();
    }
}
