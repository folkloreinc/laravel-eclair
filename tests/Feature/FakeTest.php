<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Data\ErrorCode;
use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\JobStatus;
use Folklore\Eclair\Data\TaskStatus;
use Folklore\Eclair\Exceptions\RequestFailed;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Tests\TestCase;
use Folklore\Eclair\Tests\ValidatesProtocol;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    use ValidatesProtocol;

    private const REQUEST = [
        'input' => ['url' => 'https://cdn.example.com/interview.mov'],
        'tasks' => [
            'probe' => ['op' => 'probe'],
            'h264' => ['op' => 'video.transcode', 'options' => ['codec' => 'h264'], 'after' => ['probe']],
            'hls' => ['op' => 'video.hls', 'from' => 'h264'],
            'poster' => ['op' => 'video.poster', 'from' => 'h264:h264'],
            'transcript' => ['op' => 'ai.transcribe'],
        ],
        'metadata' => ['mediaId' => 42],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Every answer of the fake follows the protocol.
        Http::globalResponseMiddleware(function ($response) {
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            if (is_array($data) && $data !== []) {
                $this->assertMatchesProtocol($this->definition($data), $body);
            }

            return $response;
        });
    }

    public function test_jobs_follow_the_protocol(): void
    {
        $fake = Eclair::fake();

        $job = Eclair::jobs()->create(self::REQUEST);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(TaskStatus::Queued, $job->task('probe')?->status);
        $this->assertSame(TaskStatus::Pending, $job->task('hls')?->status);
        $this->assertSame($job->id, Eclair::jobs()->find($job->id)->id);

        $done = $fake->completeJob($job, [
            'h264' => ['files' => [['name' => 'h264', 'path' => 'videos/42/h264.mp4', 'mime' => 'video/mp4', 'size' => 1024]]],
            'probe' => ['metadata' => ['kind' => 'video', 'mime' => 'video/quicktime', 'duration' => 10.5]],
        ]);
        $this->assertSame(JobStatus::Completed, $done->status);
        $this->assertSame('videos/42/h264.mp4', $done->task('h264')?->file()?->path);
        $this->assertSame(10.5, Eclair::jobs()->find($job->id)->task('probe')?->metadata['duration']);
        $this->assertSame(['videos/42/h264.mp4'], Eclair::jobs()->files($job->id)->map->path->all());

        $fake->assertJobCreated(fn (array $request, Job $created) => $request['metadata'] === ['mediaId' => 42] && $created->id === $job->id);
        $fake->assertJobNotCreated(fn (array $request) => isset($request['preset']));
        $fake->assertJobCount(1);
        $this->assertSame($job->id, $fake->lastJob()?->id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://eclair.example.com/v1/jobs');
    }

    public function test_a_failed_task_fails_the_tasks_that_depend_on_it(): void
    {
        $fake = Eclair::fake();
        $job = Eclair::jobs()->create(self::REQUEST);

        $failed = $fake->failJob($job, 'h264', 'timeout', 'Too long.');

        $this->assertSame(JobStatus::Failed, $failed->status);
        $this->assertSame(ErrorCode::Timeout, $failed->task('h264')?->error?->code);
        $this->assertSame(ErrorCode::DependencyFailed, $failed->task('hls')?->error?->code);
        $this->assertSame(ErrorCode::DependencyFailed, $failed->task('poster')?->error?->code);
        $this->assertSame(TaskStatus::Completed, $failed->task('probe')?->status);
        $this->assertSame(TaskStatus::Completed, $failed->task('transcript')?->status);

        $retried = Eclair::jobs()->retry($job->id);
        $this->assertSame(JobStatus::Running, $retried->status);
        $this->assertSame(TaskStatus::Queued, $retried->task('hls')?->status);
        $this->assertNull($retried->tasks['hls']->error);
    }

    public function test_jobs_can_be_canceled_and_listed(): void
    {
        $fake = Eclair::fake();
        $first = Eclair::jobs()->create([...self::REQUEST, 'metadata' => ['mediaId' => 1]]);
        $second = Eclair::jobs()->create([...self::REQUEST, 'metadata' => ['mediaId' => 2]]);

        $canceled = Eclair::jobs()->cancel($first->id);

        $this->assertSame(JobStatus::Canceled, $canceled->status);
        $this->assertSame(TaskStatus::Canceled, $canceled->task('h264')?->status);
        $fake->assertJobCanceled($first);
        $this->assertSame([$first->id], array_map(fn (Job $job) => $job->id, Eclair::jobs()->list(['status' => 'canceled'])->jobs));
        $this->assertSame([$second->id], array_map(fn (Job $job) => $job->id, Eclair::jobs()->list(['metadata' => ['mediaId' => 2]])->jobs));
        $this->assertSame([$second->id, $first->id], Eclair::jobs()->all(['limit' => 1])->map->id->all());
    }

    public function test_an_idempotency_key_returns_the_first_job(): void
    {
        $fake = Eclair::fake();

        $first = Eclair::jobs()->create(self::REQUEST, 'media-42');
        $replay = Eclair::jobs()->create(self::REQUEST, 'media-42');

        $this->assertSame($first->id, $replay->id);
        $fake->assertJobCount(1);
        try {
            Eclair::jobs()->create([...self::REQUEST, 'metadata' => ['mediaId' => 43]], 'media-42');
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(409, $exception->status);
        }
    }

    public function test_presets_are_merged_into_jobs(): void
    {
        $fake = Eclair::fake()->preset('mediatheque/video', ['probe' => ['op' => 'probe'], 'h264' => ['op' => 'video.transcode']]);

        $job = Eclair::jobs()->create(['input' => ['upload' => 'upl_01JA2Y9F3K8M2R6T4V7W9X1Z3B'], 'preset' => 'mediatheque/video', 'tasks' => ['poster' => ['op' => 'video.poster']]]);
        $this->assertSame(['probe', 'h264', 'poster'], array_keys($job->tasks));
        $this->assertSame('mediatheque/video', $job->preset);

        $saved = Eclair::presets()->put('site/audio', ['mp3' => ['op' => 'audio.transcode', 'options' => ['codec' => 'mp3']]]);
        $again = Eclair::presets()->put('site/audio', ['mp3' => ['op' => 'audio.transcode']]);
        $this->assertSame([1, 2], [$saved['version'], $again['version']]);
        $this->assertSame(['mediatheque/video', 'site/audio'], array_column(Eclair::presets()->all(), 'name'));
        $fake->assertPresetSaved('site/audio', fn (array $tasks) => ($tasks['mp3']['options']['codec'] ?? null) === 'mp3');
        Eclair::presets()->delete('site/audio');
        $this->assertSame(['mediatheque/video'], array_column(Eclair::presets()->all(), 'name'));
        $this->assertContains('video.hls', array_column(Eclair::operations(), 'name'));

        try {
            Eclair::jobs()->create(['input' => ['upload' => 'upl_01JA2Y9F3K8M2R6T4V7W9X1Z3B'], 'preset' => 'nope']);
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(['there is no preset "nope"'], $exception->errors()['/preset'] ?? null);
        }
    }

    public function test_probes_and_uploads(): void
    {
        $fake = Eclair::fake()->probeReturns(['kind' => 'image', 'mime' => 'image/png', 'width' => 10]);

        $this->assertSame(10, Eclair::probe(['url' => 'https://cdn.example.com/a.png'])->metadata['width'] ?? null);
        $this->assertSame(5, Eclair::probeFile('hello')->metadata['size'] ?? null);
        $fake->assertProbed(fn (array $request) => ($request['input']['url'] ?? null) === 'https://cdn.example.com/a.png');
        $fake->assertProbed(fn (array $request) => ($request['file'] ?? null) === 'hello');

        $upload = Eclair::uploads()->create('hello', probe: true);
        $this->assertSame('image', $upload->metadata['kind'] ?? null);
        $this->assertSame(5, Eclair::uploads()->find($upload->id)->size);
        $fake->assertUploaded(fn (string $contents) => $contents === 'hello');
        Eclair::uploads()->delete($upload->id);
        try {
            Eclair::uploads()->find($upload->id);
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(404, $exception->status);
        }

        $fake->probeReturns([], pending: true);
        $pending = Eclair::probe(['url' => 'https://cdn.example.com/long.mov']);
        $this->assertTrue($pending->pending());
        $this->assertSame(TaskStatus::Running, $pending->job?->task('probe')?->status);
    }

    public function test_files_with_contents_can_be_downloaded(): void
    {
        Storage::fake('local');
        $fake = Eclair::fake();
        $job = Eclair::jobs()->create([...self::REQUEST, 'output' => ['storage' => 'eclair']]);
        $fake->completeJob($job, ['h264' => ['files' => [['name' => 'h264', 'path' => 'h264.mp4', 'mime' => 'video/mp4', 'contents' => 'video']]]]);

        Eclair::downloadFiles($job, 'local');

        $this->assertSame('video', Storage::disk('local')->get('h264.mp4'));
    }

    public function test_assertions_fail(): void
    {
        $fake = Eclair::fake();

        $fake->assertNothingCreated();
        $this->expectException(AssertionFailedError::class);
        $fake->assertJobCreated();
    }

    public function test_the_key_is_checked(): void
    {
        Eclair::fake();
        config(['eclair.key' => 'ek_wrong']);

        try {
            Eclair::operations();
            $this->fail('No exception');
        } catch (RequestFailed $exception) {
            $this->assertSame(401, $exception->status);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function definition(array $data): string
    {
        return match (true) {
            isset($data['title'], $data['status']) && is_int($data['status']) => 'Problem',
            isset($data['data']) && array_key_exists('nextCursor', $data) => isset($data['data'][0]['task']) ? 'JobFileList' : 'JobList',
            isset($data['data']) => isset($data['data'][0]['capability']) ? 'OperationList' : 'PresetList',
            isset($data['metadata']) && count($data) === 1 => 'ProbeResult',
            isset($data['scope']) => 'Preset',
            str_starts_with((string) ($data['id'] ?? ''), 'upl_') => 'Upload',
            default => 'Job',
        };
    }
}
