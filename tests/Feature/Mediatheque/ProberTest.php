<?php

namespace Folklore\Eclair\Tests\Feature\Mediatheque;

use Carbon\CarbonImmutable;
use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Exceptions\RequestFailed;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Mediatheque\EclairProber;
use Folklore\Eclair\Tests\MediathequeTestCase;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\PipelineRun;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * A pending video whose original is an embed's page, as the mediatheque's Source::embed() makes
     * it (its decision 0029).
     */
    private function embedOriginal(): File
    {
        $media = $this->video(['status' => 'pending', 'metadata' => []]);
        $original = $media->original();
        $this->assertInstanceOf(Model::class, $original);
        $original->forceFill([
            'source' => null,
            'path' => null,
            'mime' => 'text/html',
            'provider' => 'vimeo',
            'provider_ref' => '76979871',
            'metadata' => ['provider_url' => 'https://vimeo.com/76979871'],
        ])->save();

        return $original;
    }

    public function test_an_embed_is_probed_through_its_provider_with_flat_keys(): void
    {
        $fake = Eclair::fake()->probeReturns([
            'kind' => 'video',
            'mime' => 'text/html',
            'duration' => 62,
            'width' => 1920,
            'height' => 1080,
            'embed' => [
                'provider' => 'vimeo',
                'id' => '76979871',
                'url' => 'https://vimeo.com/76979871',
                'embedUrl' => 'https://player.vimeo.com/video/76979871',
                'title' => 'The New Vimeo Player',
                'author' => ['id' => '152184', 'name' => 'Vimeo', 'url' => 'https://vimeo.com/staff'],
                'thumbnailUrl' => 'https://i.vimeocdn.com/video/452001751-d_1920x1080',
                'publishedAt' => '2013-10-15T18:08:29Z',
                'embeddable' => true,
                'privacy' => 'public',
            ],
        ]);

        $outcome = app(Prober::class)->probe($this->embedOriginal());

        $this->assertTrue($outcome->isDone());
        $this->assertSame([
            'kind' => 'video',
            'mime' => 'text/html',
            'duration' => 62,
            'width' => 1920,
            'height' => 1080,
            'provider' => 'vimeo',
            'provider_id' => '76979871',
            'provider_url' => 'https://vimeo.com/76979871',
            'embed_url' => 'https://player.vimeo.com/video/76979871',
            'title' => 'The New Vimeo Player',
            'author_id' => '152184',
            'author_name' => 'Vimeo',
            'author_url' => 'https://vimeo.com/staff',
            'thumbnail_url' => 'https://i.vimeocdn.com/video/452001751-d_1920x1080',
            'published_at' => '2013-10-15T18:08:29Z',
            'embeddable' => true,
            'privacy' => 'public',
        ], $outcome->metadata);
        // The page, as an embed: no placeholder, since there is no file
        $fake->assertProbed(fn (array $request) => $request['input'] === ['embed' => 'https://vimeo.com/76979871'] && ! isset($request['thumbhash']));
    }

    public function test_a_missing_embed_fails_with_eclairs_code_and_a_refusal_is_tried_again(): void
    {
        Http::fakeSequence('eclair.example.com/v1/probe')
            ->push(['title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'Vimeo has no such video or audio.', 'code' => 'provider_unavailable'], 422)
            ->push(['title' => 'Unprocessable Content', 'status' => 422, 'detail' => 'Vimeo limits the requests.', 'code' => 'provider_refused'], 422);
        $original = $this->embedOriginal();

        $outcome = app(Prober::class)->probe($original);
        $this->assertNotNull($outcome->error);
        // provider_unavailable from laravel-mediatheque 2.0.0-alpha.18 on; operation_failed before
        $this->assertSame(TranscoderErrorCode::fromDriver('provider_unavailable'), $outcome->error->code);
        $this->assertSame('Vimeo has no such video or audio.', $outcome->error->message);

        $this->expectException(RequestFailed::class);
        app(Prober::class)->probe($original);
    }
}
