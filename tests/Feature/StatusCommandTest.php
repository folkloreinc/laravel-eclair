<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class StatusCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'media']);
        $app['config']->set('eclair.storages', ['media' => ['disk' => 's3']]);
    }

    public function test_it_checks_the_server_the_key_and_the_storages(): void
    {
        Http::fake([
            'eclair.example.com/ready' => Http::response(['status' => 'ready', 'checks' => []]),
            'eclair.example.com/v1/operations' => Http::response(['data' => [['name' => 'probe']]]),
        ]);

        $this->artisan('eclair:status')
            ->expectsOutputToContain('The server is ready')
            ->expectsOutputToContain('the project may use 1 operations')
            ->expectsOutputToContain('The webhook secret is set')
            ->expectsOutputToContain('s3, on the bucket of the disk "s3"')
            ->assertSuccessful();
    }

    public function test_it_fails_with_a_wrong_key_or_a_disk_eclair_cannot_read(): void
    {
        config(['eclair.storages.files' => ['disk' => 'local'], 'eclair.storages.gone' => ['disk' => 'nope']]);
        Http::fake([
            'eclair.example.com/ready' => Http::response(['status' => 'unready'], 503),
            'eclair.example.com/v1/operations' => Http::response(['title' => 'Unauthorized', 'status' => 401], 401),
        ]);

        $this->artisan('eclair:status')
            ->expectsOutputToContain('The server is not ready: it answered 503')
            ->expectsOutputToContain('The key is refused (ECLAIR_KEY)')
            ->expectsOutputToContain('Eclair can\'t read the local disk "local"')
            ->expectsOutputToContain('the disk "nope" does not exist')
            ->assertFailed();
    }
}
