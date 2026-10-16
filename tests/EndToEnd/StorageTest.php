<?php

namespace Folklore\Eclair\Tests\EndToEnd;

use Folklore\Eclair\Data\JobStatus;
use Folklore\Eclair\Facades\Eclair;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * A job that reads its input from the application and writes its outputs back, through the storage
 * endpoint and the `app` storage that `eclair.sh` registers in Eclair. The application runs on PHP's
 * built-in server at `ECLAIR_E2E_APP` (`0.0.0.0:8099`), where Eclair can reach it.
 */
final class StorageTest extends EndToEndTestCase
{
    private string $root;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::env('ECLAIR_E2E_APP') === null) {
            $this->markTestSkipped('ECLAIR_E2E_APP is not set: start Eclair with tests/EndToEnd/eclair.sh.');
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        if (isset($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->root = sys_get_temp_dir().'/laravel-eclair-e2e-'.bin2hex(random_bytes(8));
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('filesystems.disks.e2e', ['driver' => 'local', 'root' => $this->root]);
            $config->set('eclair.storages', ['app' => ['disk' => 'e2e', 'serve' => true]]);
        });
    }

    public function test_a_job_reads_and_writes_the_files_of_the_application(): void
    {
        Storage::disk('e2e')->put('inputs/image.png', (string) file_get_contents(self::FIXTURE));
        $this->serve();

        $job = $this->waitFor(Eclair::jobs()->create([
            'input' => Eclair::input('e2e', 'inputs/image.png'),
            'output' => Eclair::output('e2e', 'outputs/'),
            'tasks' => ['webp' => ['op' => 'image.convert', 'options' => ['format' => 'webp']]],
        ]));

        $this->assertSame(JobStatus::Completed, $job->status, (string) json_encode($job->toArray()));
        $webp = $job->task('webp')?->file();
        $this->assertNotNull($webp);
        $this->assertStringStartsWith('outputs/', $webp->path);
        $this->assertNull($webp->url);
        $contents = (string) Storage::disk('e2e')->get($webp->path);
        $this->assertSame($webp->size, strlen($contents));
        $this->assertSame('WEBP', substr($contents, 8, 4));
    }

    /**
     * Starts the application on PHP's built-in server, with the same disk and webhook secret.
     */
    private function serve(): void
    {
        $address = (string) self::env('ECLAIR_E2E_APP');
        $this->server = new Process(
            [PHP_BINARY, '-S', $address, __DIR__.'/Server/server.php'],
            dirname(__DIR__, 2),
            [
                'ECLAIR_E2E_DISK' => $this->root,
                'ECLAIR_E2E_WEBHOOK_SECRET' => (string) self::env('ECLAIR_E2E_WEBHOOK_SECRET'),
            ],
        );
        $this->server->start();

        $url = 'http://127.0.0.1:'.substr($address, (int) strrpos($address, ':') + 1).'/eclair/storages/app/inputs/image.png';
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            try {
                // Without a signature, the endpoint refuses the request: it is up.
                if (Http::timeout(1)->get($url)->status() === 401) {
                    return;
                }
            } catch (\Throwable) {
                // Not listening yet
            }
            if (! $this->server->isRunning()) {
                $this->fail('The application did not start: '.$this->server->getErrorOutput());
            }
            usleep(100_000);
        }
        $this->fail('The application did not answer at '.$address.'.');
    }
}
