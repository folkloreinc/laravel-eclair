<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Data\ErrorCode;
use Folklore\Eclair\Data\JobStatus;
use Folklore\Eclair\Data\OutputFile;
use Folklore\Eclair\Data\TaskStatus;
use Folklore\Eclair\Exceptions\ConnectionFailed;
use Folklore\Eclair\Exceptions\EclairException;
use Folklore\Eclair\Exceptions\RequestFailed;
use Folklore\Eclair\Exceptions\StorageNotConfigured;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Tests\TestCase;
use Folklore\Eclair\Tests\ValidatesProtocol;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class ClientTest extends TestCase
{
    use ValidatesProtocol;

    private const JOB = [
        'id' => 'job_01JA2Y8C6W4N5V7TQ3H9XK0B2M',
        'status' => 'failed',
        'createdAt' => '2026-10-15T14:00:00Z',
        'startedAt' => '2026-10-15T14:00:01Z',
        'endedAt' => '2026-10-15T14:03:12Z',
        'preset' => ['name' => 'mediatheque/video', 'version' => 3],
        'metadata' => ['mediaId' => 42],
        'tasks' => [
            'probe' => [
                'status' => 'completed',
                'op' => 'probe@1',
                'outputs' => ['metadata' => ['kind' => 'video', 'mime' => 'video/quicktime', 'duration' => 184.2]],
            ],
            'h264' => [
                'status' => 'completed',
                'op' => 'video.transcode@1',
                'outputs' => ['files' => [['name' => 'h264', 'path' => 'videos/42/h264.mp4', 'mime' => 'video/mp4', 'size' => 1024, 'role' => 'main']]],
            ],
            'hls' => [
                'status' => 'failed',
                'op' => 'video.hls@1',
                'from' => 'h264',
                'error' => ['code' => 'gpu_unavailable', 'message' => 'No GPU.'],
            ],
            'webm' => ['status' => 'skipped', 'op' => 'video.transcode@1'],
        ],
    ];

    public function test_it_creates_a_job(): void
    {
        $this->assertMatchesProtocol('Job', self::JOB);
        Http::fake(['eclair.example.com/v1/jobs' => Http::response(self::JOB, 202)]);
        config([
            'eclair.webhook.url' => 'https://app.example.com/eclair/webhook',
            'eclair.storages' => ['media' => ['disk' => 's3']],
        ]);

        $job = Eclair::jobs()->create([
            'input' => Eclair::input('s3', '/uploads/interview.mov'),
            'output' => Eclair::output('s3', 'videos/42/'),
            'preset' => 'mediatheque/video',
            'metadata' => ['mediaId' => 42],
        ], 'media-42');

        Http::assertSent(function (Request $request): bool {
            $this->assertMatchesProtocol('JobRequest', $request->data());

            return $request->method() === 'POST'
                && $request->url() === 'https://eclair.example.com/v1/jobs'
                && $request->hasHeader('Authorization', 'Bearer ek_test')
                && $request->hasHeader('Idempotency-Key', 'media-42')
                && $request['input'] === ['storage' => 'media', 'path' => 'uploads/interview.mov']
                && $request['output'] === ['storage' => 'media', 'prefix' => 'videos/42/']
                && $request['webhook'] === 'https://app.example.com/eclair/webhook';
        });
        $this->assertSame('job_01JA2Y8C6W4N5V7TQ3H9XK0B2M', $job->id);
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertTrue($job->ended());
        $this->assertSame('mediatheque/video', $job->preset);
        $this->assertSame(3, $job->presetVersion);
        $this->assertSame(['mediaId' => 42], $job->metadata);
        $this->assertSame('2026-10-15T14:03:12+00:00', $job->endedAt?->toIso8601String());
        $this->assertSame(184.2, $job->task('probe')?->metadata['duration']);
        $this->assertSame(TaskStatus::Skipped, $job->task('webm')?->status);
        $this->assertSame(ErrorCode::OperationFailed, $job->task('hls')?->error?->code, 'An unknown code is operation_failed.');
        $this->assertSame('videos/42/h264.mp4', $job->task('h264')?->file()?->path);
        $this->assertSame(['h264'], array_map(fn (OutputFile $file) => $file->task, $job->files()));
        $this->assertSame(self::JOB, $job->toArray());
    }

    public function test_storages_map_to_disks(): void
    {
        config(['eclair.storages' => ['media' => ['disk' => 's3'], 'files' => ['disk' => 'local', 'serve' => true]]]);

        $this->assertSame('files', Eclair::storageFor('local'));
        $this->assertSame(['storage' => 'media', 'prefix' => 'videos/42/', 'path' => '{task}/{name}'], Eclair::output('s3', '/videos/42/', '{task}/{name}'));
        $this->expectException(StorageNotConfigured::class);
        Eclair::storageFor('public');
    }

    public function test_it_lists_jobs_by_status_date_and_metadata(): void
    {
        Http::fake([
            'eclair.example.com/v1/jobs?*cursor=page2*' => Http::response(['data' => [self::JOB], 'nextCursor' => null]),
            'eclair.example.com/v1/jobs*' => Http::response(['data' => [self::JOB, self::JOB], 'nextCursor' => 'page2']),
        ]);

        $page = Eclair::jobs()->list([
            'status' => [JobStatus::Failed, 'canceled'],
            'createdAfter' => new \DateTimeImmutable('2026-10-15T12:00:00Z'),
            'metadata' => ['mediaId' => 42, 'draft' => false],
            'limit' => 2,
        ]);
        $all = Eclair::jobs()->all(['status' => 'failed'])->all();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/jobs?'.http_build_query([
            'status' => 'failed,canceled',
            'createdAfter' => '2026-10-15T12:00:00.000+00:00',
            'metadata[mediaId]' => '42',
            'metadata[draft]' => 'false',
            'limit' => 2,
        ]));
        $this->assertCount(2, $page->jobs);
        $this->assertSame('page2', $page->nextCursor);
        $this->assertCount(3, $all);
    }

    public function test_it_encodes_ids_and_preset_names(): void
    {
        Http::fake([
            'eclair.example.com/v1/presets/*' => Http::response(['name' => 'mediatheque/video', 'scope' => 'project', 'version' => 2, 'tasks' => ['probe' => ['op' => 'probe']]]),
            'eclair.example.com/v1/jobs/*' => Http::response(self::JOB),
        ]);

        $preset = Eclair::presets()->put('mediatheque/video', ['probe' => ['op' => 'probe']]);
        Eclair::jobs()->cancel('job_01JA2Y8C6W4N5V7TQ3H9XK0B2M');
        Eclair::jobs()->find('job/../x');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $this->assertMatchesProtocol('PresetRequest', $request->data());

            return $request->url() === 'https://eclair.example.com/v1/presets/mediatheque%2Fvideo';
        });
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://eclair.example.com/v1/jobs/job_01JA2Y8C6W4N5V7TQ3H9XK0B2M/cancel');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/jobs/job%2F..%2Fx');
        $this->assertSame(2, $preset['version']);
    }

    public function test_it_probes_an_input_or_a_file(): void
    {
        Http::fake([
            'eclair.example.com/v1/probe' => Http::sequence()
                ->push(['metadata' => ['kind' => 'image', 'mime' => 'image/png', 'width' => 10]])
                ->push(self::JOB, 202),
            'eclair.example.com/v1/probe?*' => Http::response(['metadata' => ['kind' => 'other', 'mime' => 'text/plain']]),
        ]);

        $completed = Eclair::probe(['url' => 'https://cdn.example.com/a.png'], timeout: 5, checksum: true, thumbhash: true);
        $pending = Eclair::probe(['url' => 'https://cdn.example.com/b.mov']);
        $file = Eclair::probeFile('hello', checksum: true, thumbhash: true);

        $this->assertFalse($completed->pending());
        $this->assertSame(10, $completed->metadata['width'] ?? null);
        $this->assertTrue($pending->pending());
        $this->assertSame(self::JOB['id'], $pending->job?->id);
        $this->assertSame('text/plain', $file->metadata['mime'] ?? null);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://eclair.example.com/v1/probe' || ($request->data()['timeout'] ?? null) !== 5) {
                return false;
            }
            $this->assertMatchesProtocol('ProbeRequest', $request->data());

            return $request['checksum'] === true && $request['thumbhash'] === true;
        });
        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/probe?checksum=true&thumbhash=true'
            && $request->body() === 'hello'
            && $request->hasHeader('Content-Type', 'application/octet-stream'));
    }

    public function test_it_uploads_a_file(): void
    {
        $upload = ['id' => 'upl_01JA2Y9F3K8M2R6T4V7W9X1Z3B', 'size' => 5, 'expiresAt' => '2026-10-16T14:00:00Z', 'metadata' => ['kind' => 'other', 'mime' => 'text/plain']];
        $this->assertMatchesProtocol('Upload', $upload);
        Http::fake(['eclair.example.com/v1/uploads*' => Http::response($upload, 201)]);
        $file = tmpfile();
        fwrite($file, 'hello');
        rewind($file);

        $created = Eclair::uploads()->create($file, probe: true);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/uploads?probe=1' && $request->body() === 'hello');
        $this->assertSame(['upload' => 'upl_01JA2Y9F3K8M2R6T4V7W9X1Z3B'], $created->input());
        $this->assertSame('text/plain', $created->metadata['mime'] ?? null);
    }

    public function test_it_downloads_files_of_the_temporary_storage(): void
    {
        Storage::fake('local');
        Http::fake([
            'eclair.example.com/v1/jobs/*/files*' => Http::response(['data' => [
                ['task' => 'hls', 'name' => 'master', 'path' => 'videos/42/hls/master.m3u8', 'mime' => 'application/vnd.apple.mpegurl', 'size' => 7, 'url' => 'https://eclair.example.com/v1/files/a', 'expiresAt' => '2026-10-16T14:00:00Z'],
                ['task' => 'hls', 'name' => 'segment-0', 'path' => 'videos/42/hls/0.ts', 'mime' => 'video/mp2t', 'size' => 4, 'url' => 'https://bucket.example.com/0.ts?X-Amz-Signature=x', 'expiresAt' => '2026-10-16T14:00:00Z'],
            ], 'nextCursor' => null]),
            'eclair.example.com/v1/files/a' => Http::response('#EXTM3U'),
            'bucket.example.com/*' => Http::response('data'),
        ]);

        $paths = Eclair::downloadFiles(self::JOB['id'], 'local', 'hls');

        $this->assertSame(['videos/42/hls/master.m3u8', 'videos/42/hls/0.ts'], $paths);
        Storage::disk('local')->assertExists('videos/42/hls/master.m3u8');
        $this->assertSame('data', Storage::disk('local')->get('videos/42/hls/0.ts'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/jobs/'.self::JOB['id'].'/files?task=hls');
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bucket.example.com/') && ! $request->hasHeader('Authorization'));
    }

    public function test_a_file_without_url_cannot_be_downloaded(): void
    {
        $this->expectException(EclairException::class);
        Eclair::download(OutputFile::fromArray(['name' => 'h264', 'path' => 'a.mp4', 'mime' => 'video/mp4', 'size' => 1, 'expired' => true]), 'local');
    }

    public function test_errors_have_the_problem_details(): void
    {
        $problem = ['type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422, 'errors' => ['/tasks/h264/op' => ['"video.nope" is not an operation of this server']]];
        $this->assertMatchesProtocol('Problem', $problem);
        Http::fake([
            'eclair.example.com/v1/jobs' => Http::response($problem, 422, ['Content-Type' => 'application/problem+json']),
            'eclair.example.com/v1/presets' => Http::response(['title' => 'Too Many Requests', 'status' => 429], 429, ['Retry-After' => '12']),
            'eclair.example.com/v1/operations' => Http::response('Bad gateway', 502),
        ]);

        try {
            Eclair::jobs()->create(['input' => ['url' => 'https://cdn.example.com/a.mp4'], 'tasks' => ['h264' => ['op' => 'video.nope']]]);
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame(['/tasks/h264/op' => ['"video.nope" is not an operation of this server']], $exception->errors());
            $this->assertSame('Eclair answered 422 Unprocessable Content', $exception->getMessage());
        }
        try {
            Eclair::presets()->all();
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(12, $exception->retryAfter);
        }
        try {
            Eclair::operations();
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(502, $exception->status);
            $this->assertSame('Bad Gateway', $exception->problem['title']);
        }
    }

    public function test_connection_errors_are_connection_failed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Connection refused'));

        $this->expectException(ConnectionFailed::class);
        Eclair::operations();
    }

    public function test_it_needs_a_url_and_a_key(): void
    {
        config(['eclair.key' => null]);

        $this->expectExceptionMessage('Eclair is not configured: set ECLAIR_URL and ECLAIR_KEY.');
        Eclair::operations();
    }
}
