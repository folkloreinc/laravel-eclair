<?php

namespace Folklore\Eclair\Tests;

use Folklore\Eclair\Testing\InteractsWithEclair;
use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\ServiceProvider as MediathequeServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Tests of the `eclair` driver with laravel-mediatheque 2: its tables, models and Eloquent
 * recorder, on SQLite in memory, and media on a fake `s3` disk mapped to the Eclair storage
 * `media`.
 */
abstract class MediathequeTestCase extends TestCase
{
    use InteractsWithEclair;
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [MediathequeServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('eclair.storages', ['media' => ['disk' => 's3']]);
            $config->set('mediatheque.source', 's3');
            $config->set('mediatheque.pipelines.video.transcoder', 'eclair');
            $config->set('mediatheque.pipelines.video.renditions', [
                'h264' => ['op' => 'video.transcode', 'max_width' => 1920],
                'poster' => ['op' => 'video.poster', 'from' => 'h264', 'role' => 'poster'],
                'thumbnails' => ['op' => 'video.thumbnails', 'count' => 4, 'role' => 'thumbnail'],
            ]);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(InstallCommand::migrations());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    /**
     * A probed video and its original on the `s3` disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function video(array $attributes = []): Media
    {
        $media = new Media;
        $media->forceFill([
            'type' => 'video',
            'name' => 'Interview',
            'status' => 'processing',
            'visibility' => 'private',
            'metadata' => ['kind' => 'video', 'mime' => 'video/quicktime', 'duration' => 100.0, 'width' => 1920, 'height' => 1080],
            ...$attributes,
        ])->save();
        $original = new File;
        $original->forceFill([
            'media_id' => $media->id,
            'handle' => 'original',
            'role' => 'original',
            'source' => 's3',
            'path' => 'uploads/'.$media->uid.'/interview.mov',
            'mime' => 'video/quicktime',
            'size' => 5,
        ])->save();
        Storage::disk('s3')->put($original->path, 'movie');

        return $media->refresh();
    }
}
