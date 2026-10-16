<?php

namespace Folklore\Eclair\Tests\EndToEnd;

use Folklore\Eclair\Data\JobStatus;
use Folklore\Eclair\Exceptions\RequestFailed;
use Folklore\Eclair\Facades\Eclair;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ClientTest extends EndToEndTestCase
{
    public function test_it_probes_a_file(): void
    {
        $result = Eclair::probeFile(new \SplFileInfo(self::FIXTURE), checksum: true);

        $this->assertFalse($result->pending());
        $this->assertSame('image', $result->metadata['kind'] ?? null);
        $this->assertSame(64, $result->metadata['width'] ?? null);
        $this->assertSame(48, $result->metadata['height'] ?? null);
        $this->assertSame(hash_file('sha256', self::FIXTURE), $result->metadata['checksum'] ?? null);
    }

    public function test_it_probes_an_upload(): void
    {
        $upload = Eclair::uploads()->create((string) file_get_contents(self::FIXTURE), probe: true);

        $this->assertStringStartsWith('upl_', $upload->id);
        $this->assertSame(filesize(self::FIXTURE), $upload->size);
        $this->assertSame('image', $upload->metadata['kind'] ?? null);
        $this->assertSame($upload->id, Eclair::uploads()->find($upload->id)->id);

        $result = Eclair::probe($upload->input());

        $this->assertFalse($result->pending());
        $this->assertSame('image/png', $result->metadata['mime'] ?? null);

        Eclair::uploads()->delete($upload->id);

        try {
            Eclair::uploads()->find($upload->id);
            $this->fail('The upload was not deleted.');
        } catch (RequestFailed $exception) {
            $this->assertSame(404, $exception->status);
        }
    }

    public function test_it_runs_a_job_and_downloads_its_files(): void
    {
        Storage::fake('local');
        $run = (string) Str::ulid();
        $upload = Eclair::uploads()->create(fopen(self::FIXTURE, 'r'));

        $job = Eclair::jobs()->create([
            'input' => $upload->input(),
            'output' => ['storage' => 'eclair', 'prefix' => 'e2e/'],
            'tasks' => [
                'probe' => ['op' => 'probe'],
                'webp' => ['op' => 'image.convert', 'options' => ['format' => 'webp']],
            ],
            'metadata' => ['run' => $run],
        ]);
        $this->assertStringStartsWith('job_', $job->id);

        $job = $this->waitFor($job);

        $this->assertSame(JobStatus::Completed, $job->status, (string) json_encode($job->toArray()));
        $this->assertSame(64, $job->task('probe')?->metadata['width'] ?? null);
        $webp = $job->task('webp')?->file();
        $this->assertNotNull($webp);
        $this->assertSame('image/webp', $webp->mime);
        $this->assertNotNull($webp->url);

        $this->assertSame([$job->id], array_map(
            fn ($job) => $job->id,
            Eclair::jobs()->list(['metadata' => ['run' => $run]])->jobs,
        ));

        $paths = Eclair::downloadFiles($job, 'local');

        $this->assertContains($webp->path, $paths);
        $contents = (string) Storage::disk('local')->get($webp->path);
        $this->assertSame($webp->size, strlen($contents));
        $this->assertSame('RIFF', substr($contents, 0, 4));
        $this->assertSame('WEBP', substr($contents, 8, 4));
    }

    public function test_it_manages_presets(): void
    {
        $name = 'e2e-'.Str::lower((string) Str::ulid());
        $tasks = ['webp' => ['op' => 'image.convert', 'options' => ['format' => 'webp']]];

        $preset = Eclair::presets()->put($name, $tasks);

        $this->assertSame($name, $preset['name']);
        $this->assertSame('project', $preset['scope']);
        $this->assertSame(1, $preset['version']);

        $tasks['webp']['options']['maxWidth'] = 32;
        $this->assertSame(2, Eclair::presets()->put($name, $tasks)['version']);
        $this->assertSame(32, Eclair::presets()->find($name)['tasks']['webp']['options']['maxWidth'] ?? null);

        $names = array_column(Eclair::presets()->all(), 'name');
        $this->assertContains($name, $names);
        $this->assertContains('web-image', $names);

        Eclair::presets()->delete($name);

        try {
            Eclair::presets()->find($name);
            $this->fail('The preset was not deleted.');
        } catch (RequestFailed $exception) {
            $this->assertSame(404, $exception->status);
        }
    }

    public function test_it_lists_the_operations(): void
    {
        $operations = array_column(Eclair::operations(), null, 'name');

        $this->assertArrayHasKey('probe', $operations);
        $this->assertArrayHasKey('image.convert', $operations);
        $this->assertArrayHasKey('video.transcode', $operations);
        $this->assertSame('video', $operations['video.transcode']['capability'] ?? null);
    }

    public function test_an_invalid_job_has_its_validation_errors(): void
    {
        try {
            Eclair::jobs()->create([
                'input' => ['url' => 'https://example.com/image.png'],
                'tasks' => ['webp' => ['op' => 'image.convert', 'options' => ['format' => 'bmp']]],
            ]);
            $this->fail('Eclair accepted an invalid job.');
        } catch (RequestFailed $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertNotSame([], $exception->errors());
            foreach ($exception->errors() as $pointer => $messages) {
                $this->assertStringStartsWith('/tasks/webp', $pointer);
                $this->assertNotSame([], $messages);
            }
        }
    }

    public function test_an_idempotency_key_returns_the_first_job(): void
    {
        $key = (string) Str::uuid();
        $upload = Eclair::uploads()->create(fopen(self::FIXTURE, 'r'));
        $request = ['input' => $upload->input(), 'tasks' => ['probe' => ['op' => 'probe']]];

        $first = Eclair::jobs()->create($request, $key);
        $replay = Eclair::jobs()->create($request, $key);

        $this->assertSame($first->id, $replay->id);

        try {
            Eclair::jobs()->create([...$request, 'metadata' => ['other' => true]], $key);
            $this->fail('Eclair accepted the key with another request.');
        } catch (RequestFailed $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(JobStatus::Completed, $this->waitFor($first)->status);
    }

    public function test_the_status_command_reaches_the_server(): void
    {
        $this->artisan('eclair:status')
            ->expectsOutputToContain('The key works')
            ->assertSuccessful();
    }
}
