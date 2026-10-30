<?php

namespace Folklore\Eclair\Tests\Feature\Mediatheque;

use Carbon\CarbonImmutable;
use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Mediatheque\EclairProber;
use Folklore\Eclair\Tests\MediathequeTestCase;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\PipelineRun;
use Illuminate\Support\Facades\Http;

final class ProberTest extends MediathequeTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('eclair.mediatheque.prober', true);
    }

    public function test_it_replaces_the_mediatheques_prober_when_on(): void
    {
        $this->assertInstanceOf(EclairProber::class, app(Prober::class));
        config(['eclair.mediatheque.prober' => false]);
        $this->app->forgetInstance(Prober::class);
        $this->assertNotInstanceOf(EclairProber::class, app(Prober::class));
    }

    public function test_a_probe_gives_the_metadata_in_the_mediatheques_terms(): void
    {
        $fake = Eclair::fake()->probeReturns(['kind' => 'video', 'mime' => 'video/mp4', 'width' => 1920, 'height' => 1080, 'rotation' => 90, 'frameRate' => 30, 'duration' => 12.5]);
        $media = $this->video(['status' => 'pending', 'metadata' => []]);

        $original = $media->original();
        $this->assertNotNull($original);
        $outcome = app(Prober::class)->probe($original);

        $this->assertTrue($outcome->isDone());
        $this->assertSame(1080, $outcome->metadata['width']);
        $this->assertSame(1920, $outcome->metadata['height']);
        $this->assertSame(30, $outcome->metadata['frame_rate']);
        $fake->assertProbed(fn (array $request) => $request['input'] === ['storage' => 'media', 'path' => $original->path()] && $request['timeout'] === 10 && $request['thumbhash'] === true);
    }

    public function test_a_file_eclair_cannot_read_fails_the_probe(): void
    {
        Http::fake(['eclair.example.com/v1/probe' => Http::response(['title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'Not a media file.'], 422)]);
        $media = $this->video(['status' => 'pending', 'metadata' => []]);

        $original = $media->original();
        $this->assertNotNull($original);
        $outcome = app(Prober::class)->probe($original);

        $this->assertTrue($outcome->isFailed());
        $this->assertNotNull($outcome->error);
        $this->assertSame(TranscoderErrorCode::UnsupportedInput, $outcome->error->code);
        $this->assertSame('Not a media file.', $outcome->error->message);
    }

    public function test_a_long_probe_completes_with_its_webhook_then_runs_the_pipeline(): void
    {
        $fake = Eclair::fake()->probeReturns([], pending: true);
        $media = $this->video(['status' => 'pending', 'metadata' => []]);

        dispatch_sync(new ProbeMedia($media->id));

        $probe = PipelineRun::query()->where('name', 'probe')->firstOrFail();
        $this->assertNotNull($probe->externalId());
        $this->assertSame('pending', $media->refresh()->status()->value);

        $job = $fake->completeJob((string) $probe->externalId(), ['probe' => ['metadata' => ['kind' => 'video', 'mime' => 'video/quicktime', 'duration' => 40.0]]]);
        $this->postEclairWebhook($job)->assertNoContent();

        $this->assertSame(PipelineStatus::Completed, $probe->refresh()->status());
        $media->refresh();
        $this->assertEquals(40, $media->metadata('duration'));
        $this->assertSame('processing', $media->status()->value);
        $fake->assertJobCreated(fn (array $request) => ($request['metadata']['mediatheque']['pipeline'] ?? null) === 'video');
    }

    public function test_the_webhook_of_a_probe_that_answered_in_time_is_ignored(): void
    {
        Eclair::fake()->probeReturns(['kind' => 'video', 'mime' => 'video/mp4', 'duration' => 12.5]);
        $media = $this->video(['status' => 'pending', 'metadata' => []]);

        dispatch_sync(new ProbeMedia($media->id));

        $probe = PipelineRun::query()->where('name', 'probe')->firstOrFail();
        $this->assertSame(PipelineStatus::Completed, $probe->status());
        $this->assertNull($probe->externalId());

        // Eclair answered with the metadata, and still sends the probe job's webhook
        $now = CarbonImmutable::now();
        $job = Job::fromArray([
            'id' => 'job_01JA2Y8C6W4N5V7TQ3H9XK0B2M',
            'status' => 'completed',
            'createdAt' => $now->subMilliseconds(400)->toIso8601ZuluString('millisecond'),
            'startedAt' => $now->subMilliseconds(300)->toIso8601ZuluString('millisecond'),
            'endedAt' => $now->toIso8601ZuluString('millisecond'),
            'input' => ['storage' => 'media', 'path' => 'interview.mp4'],
            'metadata' => [],
            'tasks' => ['probe' => ['status' => 'completed', 'op' => 'probe@1', 'outputs' => ['metadata' => ['kind' => 'video', 'mime' => 'video/mp4', 'duration' => 12.5]]]],
        ]);
        $this->postEclairWebhook($job)->assertNoContent();

        $this->assertSame(PipelineStatus::Completed, $probe->refresh()->status());
        $this->assertEquals(12.5, $media->refresh()->metadata('duration'));
    }

    public function test_a_probe_webhook_before_the_job_id_is_recorded_is_delivered_again(): void
    {
        $fake = Eclair::fake()->probeReturns([], pending: true);
        $media = $this->video(['status' => 'pending', 'metadata' => []]);
        $result = Eclair::probe(Eclair::input('s3', 'uploads/'.$media->uid.'/interview.mov'));
        $this->assertNotNull($result->job);
        $job = $fake->completeJob($result->job->id, ['probe' => ['metadata' => ['kind' => 'video', 'mime' => 'video/quicktime']]]);

        $this->postEclairWebhook($job, delivery: 'whd_1')->assertServerError();
    }
}
