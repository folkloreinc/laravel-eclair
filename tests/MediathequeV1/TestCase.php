<?php

namespace Folklore\Eclair\Tests\MediathequeV1;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Testing\EclairFake;
use Folklore\Eclair\Tests\TestCase as BaseTestCase;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Metadata\Value;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\ServiceProvider as MediathequeServiceProvider;
use Folklore\Mediatheque\Support\PipelineJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * The jobs of laravel-mediatheque 1.1 on Eclair, with the mediatheque's tables and models, a fake
 * `s3` disk as the `cloud` source, mapped to the Eclair storage `media`. CI runs them with
 * laravel-mediatheque ^1.1 instead of 2 (`composer test:v1`).
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected EclairFake $eclair;

    protected function setUp(): void
    {
        if (! class_exists(PipelineJob::class)) {
            $this->markTestSkipped('laravel-mediatheque 1.1 is not installed.');
        }
        parent::setUp();
        Storage::fake('s3');
        $this->eclair = Eclair::fake();
    }

    protected function getPackageProviders($app): array
    {
        return class_exists(PipelineJob::class) ? [MediathequeServiceProvider::class, ...parent::getPackageProviders($app)] : parent::getPackageProviders($app);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('filesystems.cloud', 's3');
        $config->set('mediatheque.source', 'cloud');
        $config->set('mediatheque.sources.cloud', ['driver' => 'filesystem', 'disk' => 'cloud', 'path' => '/']);
        $config->set('eclair.storages', ['media' => ['disk' => 's3']]);
    }

    /**
     * An original on the `cloud` source, with its metadata by name (`width`, `height`).
     *
     * @param  array<string, int>  $metadata
     */
    protected function original(string $type = 'video', string $mime = 'video/mp4', string $extension = 'mp4', array $metadata = []): File
    {
        Storage::disk('s3')->put('uploads/original.'.$extension, 'original');
        $file = app(FileContract::class);
        $this->assertInstanceOf(File::class, $file);
        $file->setFileFromSource('uploads/original.'.$extension, [
            'source' => 'cloud',
            'type' => $type,
            'mime' => $mime,
            'extension' => $extension,
            'size' => 8,
            'metadata' => array_map(fn (string $name, int $value) => new Value($name, $value, 'integer'), array_keys($metadata), $metadata),
        ]);
        $file->save();
        // As the pipeline gets it, with its metadata
        $file = $file->fresh();
        $this->assertInstanceOf(File::class, $file);

        return $file;
    }

    /**
     * Completes the next Eclair job once the job waits for it, writing its files to the disk as
     * Eclair would: `$outputs` gives each task its files, by name, and its metadata.
     *
     * @param  array<string, array{files?: list<string>, metadata?: array<string, mixed>}>  $outputs
     */
    protected function completeWith(array $outputs, int $polls = 1): void
    {
        Sleep::fake();
        $count = 0;
        Sleep::whenFakingSleep(function () use ($outputs, $polls, &$count): void {
            if (++$count !== $polls) {
                return;
            }
            $job = $this->eclair->lastJob();
            $this->assertInstanceOf(Job::class, $job);
            $prefix = (string) ($job->toArray()['output']['prefix'] ?? '');
            $results = [];
            foreach ($outputs as $task => $output) {
                $files = [];
                foreach ($output['files'] ?? [] as $name) {
                    $path = $prefix.$task.'/'.$name;
                    // Without an ACL, as Eclair writes them
                    Storage::disk('s3')->put($path, 'output', 'private');
                    $files[] = ['name' => pathinfo($name, PATHINFO_FILENAME), 'path' => $path, 'mime' => self::mime($name), 'size' => 6];
                }
                $results[$task] = array_filter(['files' => $files, 'metadata' => $output['metadata'] ?? null]);
            }
            $this->eclair->completeJob($job, $results);
        });
    }

    private static function mime(string $name): string
    {
        return match (pathinfo($name, PATHINFO_EXTENSION)) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'mp3' => 'audio/mpeg',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };
    }
}
