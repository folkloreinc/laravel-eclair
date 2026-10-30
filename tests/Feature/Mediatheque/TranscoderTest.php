<?php

namespace Folklore\Eclair\Tests\Feature\Mediatheque;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Mediatheque\EclairTranscoder;
use Folklore\Eclair\Support\Signature;
use Folklore\Eclair\Tests\MediathequeTestCase;
use Folklore\Eclair\Tests\ValidatesProtocol;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class TranscoderTest extends MediathequeTestCase
{
    use ValidatesProtocol;

    private function run_(Media $media): PipelineRun
    {
        $run = app(PipelineRunner::class)->run($media);
        $this->assertInstanceOf(PipelineRun::class, $run);

        return $run;
    }

    public function test_a_run_is_one_job_with_a_task_per_rendition(): void
    {
        $fake = Eclair::fake();
        $media = $this->video();

        $run = $this->run_($media);

        $job = $fake->lastJob();
        $this->assertNotNull($job);
        $this->assertSame($job->id, $run->refresh()->externalId());
        $fake->assertJobCreated(function (array $request) use ($run, $media): bool {
            $this->assertMatchesProtocol('JobRequest', $request);
            $this->assertSame(['storage' => 'media', 'path' => 'uploads/'.$media->uid.'/interview.mov'], $request['input']);
            $this->assertSame(['storage' => 'media', 'prefix' => $run->pathPrefix().'/'], $request['output']);
            $this->assertSame(['run' => $run->key(), 'media' => $media->uid, 'pipeline' => 'video'], $request['metadata']['mediatheque']);
            $this->assertSame([
                'h264' => ['op' => 'video.transcode', 'options' => ['maxWidth' => 1920, 'quality' => 23, 'audioBitrate' => 128]],
                'poster' => ['op' => 'video.poster', 'options' => ['time' => 10, 'maxWidth' => 1920], 'from' => 'h264'],
                'thumbnails' => ['op' => 'video.thumbnails', 'options' => ['times' => [12.5, 37.5, 62.5, 87.5], 'maxWidth' => 1920]],
            ], $request['tasks']);

            return true;
        });
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'mediatheque-'.$run->key()));
    }

    public function test_renditions_whose_condition_does_not_match_are_skipped_with_those_that_read_from_them(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'when' => ['min_duration' => 600]],
            'poster' => ['op' => 'video.poster', 'from' => 'h264', 'role' => 'poster'],
            'thumbnails' => ['op' => 'video.thumbnails', 'role' => 'thumbnail'],
        ]]);
        $fake = Eclair::fake();

        $run = $this->run_($this->video());

        $fake->assertJobCreated(fn (array $request) => array_keys($request['tasks']) === ['thumbnails']);
        $this->assertSame(JobStatus::Skipped, $run->refresh()->job('h264')?->status());
        $this->assertSame(JobStatus::Skipped, $run->job('poster')?->status());
        $this->assertSame(JobStatus::Queued, $run->job('thumbnails')?->status());
    }

    public function test_a_run_with_every_rendition_skipped_sends_no_job(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'when' => ['kind' => 'audio']],
        ]]);
        $fake = Eclair::fake();

        $run = $this->run_($this->video());

        $fake->assertNothingCreated();
        $this->assertNull($run->refresh()->externalId());
        $this->assertSame(PipelineStatus::Completed, $run->status());
    }

    public function test_the_webhooks_of_the_job_record_the_renditions(): void
    {
        $fake = Eclair::fake();
        $media = $this->video();
        $run = $this->run_($media);
        $prefix = $run->pathPrefix();
        Storage::disk('s3')->put($prefix.'/h264/video.mp4', 'video');
        $job = $fake->completeTask($run->externalId() ?? '', 'h264', [
            'files' => [['name' => 'video', 'path' => $prefix.'/h264/video.mp4', 'mime' => 'video/mp4', 'size' => 5]],
            'metadata' => ['width' => 1920, 'height' => 1080, 'duration' => 100.0, 'bitrate' => 4000000, 'video' => 'copied', 'audio' => 'encoded'],
        ]);

        $this->postEclairWebhook($job, 'task.completed', 'h264')->assertNoContent();

        $run = $run->refresh();
        $this->assertSame(JobStatus::Completed, $run->job('h264')?->status());
        $this->assertSame(JobStatus::Queued, $run->job('thumbnails')?->status());
        $file = $media->refresh()->file('h264');
        $this->assertNotNull($file);
        $this->assertSame($prefix.'/h264/video.mp4', $file->path());
        $this->assertSame([1920, 1080, 100.0, 4000000], [$file->width(), $file->height(), $file->duration(), $file->bitrate()]);
        $this->assertSame(['copied', 'encoded'], [$file->metadata('video'), $file->metadata('audio')], 'Whether video.transcode copied or encoded each stream.');

        $thumbnails = array_map(fn (int $index) => ['name' => (string) $index, 'path' => $prefix.'/thumbnails/'.$index.'.jpg', 'mime' => 'image/jpeg', 'size' => 1], range(0, 3));
        foreach ($thumbnails as $thumbnail) {
            Storage::disk('s3')->put($thumbnail['path'], 't');
        }
        Storage::disk('s3')->put($prefix.'/poster/poster.jpg', 'p');
        $job = $fake->completeJob($job, [
            'poster' => ['files' => [['name' => 'poster', 'path' => $prefix.'/poster/poster.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'role' => 'poster']]],
            'thumbnails' => ['files' => $thumbnails, 'metadata' => ['width' => 1920, 'height' => 1080]],
        ]);
        $this->postEclairWebhook($job)->assertNoContent();

        $run = $run->refresh();
        $this->assertSame(PipelineStatus::Completed, $run->status());
        $this->assertSame([0, 1, 2, 3], $media->refresh()->files('thumbnails')->map(fn ($file) => $file->index())->values()->all());
        $this->assertSame('poster', $media->file('poster')?->role());
        $this->assertSame('ready', $media->status()->value);
    }

    public function test_an_older_snapshot_changes_nothing(): void
    {
        $fake = Eclair::fake();
        $run = $this->run_($this->video());
        $pending = $fake->job($run->externalId() ?? '');
        $this->assertInstanceOf(Job::class, $pending);
        $failed = $fake->failJob($pending, 'h264', 'timeout', 'Too long.');
        $this->postEclairWebhook($failed)->assertNoContent();

        // An older delivery, retried after the newer one
        $body = json_encode([...$failed->toArray(), 'status' => 'running', 'tasks' => array_map(fn ($task) => ['op' => $task['op'], 'status' => 'running'], $failed->toArray()['tasks'])]);
        $this->assertIsString($body);
        $headers = $fake->webhook($failed, 'task.completed', 'h264')['headers'];
        $headers['Eclair-Signature'] = Signature::sign('whsec_test', $body);
        $headers['Eclair-Delivery'] = 'whd_old';
        $this->call('POST', '/eclair/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertNoContent();

        $run = $run->refresh();
        $this->assertSame(PipelineStatus::Failed, $run->status());
        $this->assertSame('timeout', $run->job('h264')?->error()?->code->value);
        $this->assertSame('dependency_failed', $run->job('poster')?->error()?->code->value);
        $this->assertSame(JobStatus::Completed, $run->job('thumbnails')?->status());
    }

    public function test_progress_webhooks_record_the_progress_of_a_rendition(): void
    {
        $fake = Eclair::fake();
        $run = $this->run_($this->video());
        $job = $fake->progressTask($run->externalId() ?? '', 'h264', 0.5);

        $this->postEclairWebhook($job, 'task.progress', 'h264')->assertNoContent();

        $h264 = $run->refresh()->job('h264');
        $this->assertNotNull($h264);
        $this->assertSame(JobStatus::Running, $h264->status());
        $this->assertSame(0.5, $h264->progress());

        // A progress that comes after the rendition completed changes nothing.
        $completed = $fake->completeTask($job, 'h264');
        $this->postEclairWebhook($completed, 'task.completed', 'h264')->assertNoContent();
        $this->postEclairWebhook($job, 'task.progress', 'h264')->assertNoContent();
        $this->assertSame(JobStatus::Completed, $run->refresh()->job('h264')?->status());
    }

    public function test_public_runs_make_their_files_public(): void
    {
        $fake = Eclair::fake();
        $run = $this->run_($this->video(['visibility' => 'public']));
        $prefix = $run->pathPrefix();
        Storage::disk('s3')->put($prefix.'/h264/video.mp4', 'video', 'private');

        $job = $fake->completeTask($run->externalId() ?? '', 'h264', ['files' => [['name' => 'video', 'path' => $prefix.'/h264/video.mp4', 'mime' => 'video/mp4', 'size' => 5]]]);
        $this->postEclairWebhook($job, 'task.completed', 'h264')->assertNoContent();

        $this->assertSame('public', Storage::disk('s3')->getVisibility($prefix.'/h264/video.mp4'));
    }

    public function test_cancel_and_refresh_reconcile_the_job(): void
    {
        $fake = Eclair::fake();
        $run = $this->run_($this->video());
        $transcoder = app(TranscoderManager::class)->driver('eclair');
        $this->assertInstanceOf(EclairTranscoder::class, $transcoder);

        $fake->completeTask($run->externalId() ?? '', 'h264');
        $transcoder->refresh($run);
        $this->assertSame(JobStatus::Completed, $run->refresh()->job('h264')?->status());

        $transcoder->cancel($run);

        $run = $run->refresh();
        $fake->assertJobCanceled($run->externalId() ?? '');
        $this->assertSame(PipelineStatus::Canceled, $run->status());
        $this->assertSame(JobStatus::Canceled, $run->job('poster')?->status());
    }

    public function test_it_supports_eclair_operations_only(): void
    {
        $transcoder = app(TranscoderManager::class)->driver('eclair');

        $this->assertTrue($transcoder->supports('video.hls'));
        $this->assertTrue($transcoder->supports('video.transcode@1'));
        $this->assertFalse($transcoder->supports('ai.transcribe'));
        config(['eclair.mediatheque.operations' => ['ai.transcribe']]);
        $this->assertTrue($transcoder->supports('ai.transcribe'));
        $this->assertTrue($transcoder->capabilities()->cancel);
    }

    public function test_progress_is_pushed_when_the_project_sends_progress_webhooks(): void
    {
        $transcoder = app(TranscoderManager::class)->driver('eclair');
        $this->assertSame(ProgressReporting::None, $transcoder->capabilities()->progress);

        config(['eclair.mediatheque.progress' => true]);

        $this->assertSame(ProgressReporting::Pushed, $transcoder->capabilities()->progress);
    }

    public function test_a_webhook_for_a_deleted_run_is_accepted(): void
    {
        $fake = Eclair::fake();
        $job = Eclair::jobs()->create([
            'input' => ['url' => 'https://cdn.example.com/a.mov'],
            'tasks' => ['h264' => ['op' => 'video.transcode']],
            'metadata' => ['mediatheque' => ['run' => 'run_gone']],
        ]);

        $this->postEclairWebhook($fake->completeJob($job))->assertNoContent();
    }
}
