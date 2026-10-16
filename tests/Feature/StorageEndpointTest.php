<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Support\Signature;
use Folklore\Eclair\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

final class StorageEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('eclair.storage_endpoint.route', true);
        $app['config']->set('eclair.storages', [
            'files' => ['disk' => 'local', 'serve' => true],
            'media' => ['disk' => 's3'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_eclair_writes_an_output(): void
    {
        $this->write('files', 'videos/42/a b.mp4', 'video')->assertNoContent();

        $this->assertSame('video', Storage::disk('local')->get('videos/42/a b.mp4'));
    }

    public function test_eclair_reads_an_input(): void
    {
        Storage::disk('local')->put('uploads/interview.mov', 'movie');

        $response = $this->read('files', 'uploads/interview.mov');

        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('movie', $response->streamedContent());
        $this->read('files', 'uploads/missing.mov')->assertNotFound();
    }

    public function test_it_refuses_unsigned_or_changed_requests(): void
    {
        Storage::disk('local')->put('a.txt', 'a');

        $this->write('files', 'b.txt', 'b', signedBody: 'other')->assertUnauthorized();
        $this->write('files', 'b.txt', 'b', signedPath: 'c.txt')->assertUnauthorized();
        $this->write('files', 'b.txt', 'b', secret: 'whsec_other')->assertUnauthorized();
        $this->read('files', 'a.txt', signedPath: 'b.txt')->assertUnauthorized();
        $this->call('GET', '/eclair/storages/files/a.txt')->assertUnauthorized();
        Storage::disk('local')->assertMissing('b.txt');
    }

    public function test_it_serves_only_the_storages_with_serve(): void
    {
        $this->write('media', 'a.txt', 'a')->assertNotFound();
        $this->write('unknown', 'a.txt', 'a')->assertNotFound();
    }

    public function test_it_refuses_paths_out_of_the_storage(): void
    {
        foreach (['../.env', 'a/../../b', './a', 'a//b'] as $path) {
            $this->write('files', $path, 'x')->assertNotFound();
        }
    }

    public function test_it_limits_the_size_of_a_file(): void
    {
        config(['eclair.storage_endpoint.max_size' => 3]);

        $this->write('files', 'a.txt', 'abcd')->assertStatus(413);
        $this->write('files', 'a.txt', 'abc')->assertNoContent();
    }

    /**
     * @return TestResponse<Response>
     */
    private function write(string $storage, string $path, string $body, ?string $signedPath = null, ?string $signedBody = null, string $secret = 'whsec_test'): TestResponse
    {
        $signature = Signature::sign($secret, Signature::storagePayload($signedPath ?? $path, hash('sha256', $signedBody ?? $body)));

        return $this->call('PUT', $this->url($storage, $path), [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'video/mp4',
            'Eclair-Job' => 'job_01JA2Y8C6W4N5V7TQ3H9XK0B2M',
            'Eclair-Content-Sha256' => hash('sha256', $signedBody ?? $body),
            'Eclair-Signature' => $signature,
        ]), $body);
    }

    /**
     * @return TestResponse<Response>
     */
    private function read(string $storage, string $path, ?string $signedPath = null): TestResponse
    {
        $signature = Signature::sign('whsec_test', Signature::storagePayload($signedPath ?? $path, Signature::EMPTY_SHA256));

        return $this->call('GET', $this->url($storage, $path), [], [], [], $this->transformHeadersToServerVars(['Eclair-Signature' => $signature]));
    }

    /** As Eclair encodes it: each segment with encodeURIComponent. */
    private function url(string $storage, string $path): string
    {
        return '/eclair/storages/'.$storage.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
