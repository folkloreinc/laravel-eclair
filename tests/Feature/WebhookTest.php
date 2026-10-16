<?php

namespace Folklore\Eclair\Tests\Feature;

use Folklore\Eclair\Events\JobCanceled;
use Folklore\Eclair\Events\JobCompleted;
use Folklore\Eclair\Events\JobFailed;
use Folklore\Eclair\Events\TaskCompleted;
use Folklore\Eclair\Events\WebhookReceived;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Support\Signature;
use Folklore\Eclair\Testing\InteractsWithEclair;
use Folklore\Eclair\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class WebhookTest extends TestCase
{
    use InteractsWithEclair;

    private const REQUEST = [
        'input' => ['url' => 'https://cdn.example.com/a.mov'],
        'tasks' => ['h264' => ['op' => 'video.transcode'], 'poster' => ['op' => 'video.poster', 'from' => 'h264']],
    ];

    public function test_it_dispatches_the_events_of_a_job(): void
    {
        Event::fake();
        $fake = Eclair::fake();
        $completed = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));
        $failed = $fake->failJob(Eclair::jobs()->create(self::REQUEST), 'h264');
        $canceled = Eclair::jobs()->cancel(Eclair::jobs()->create(self::REQUEST)->id);

        $this->postEclairWebhook($completed)->assertNoContent();
        $this->postEclairWebhook($failed->id)->assertNoContent();
        $this->postEclairWebhook($canceled)->assertNoContent();
        $this->postEclairWebhook($completed, 'task.completed', 'poster')->assertNoContent();

        Event::assertDispatched(JobCompleted::class, fn (JobCompleted $event) => $event->job->id === $completed->id && str_starts_with($event->delivery, 'whd_'));
        Event::assertDispatched(JobFailed::class, fn (JobFailed $event) => $event->job->task('poster')?->error?->code->value === 'dependency_failed');
        Event::assertDispatched(JobCanceled::class, fn (JobCanceled $event) => $event->job->id === $canceled->id);
        Event::assertDispatched(TaskCompleted::class, fn (TaskCompleted $event) => $event->task->name === 'poster' && $event->job->id === $completed->id);
        Event::assertDispatchedTimes(WebhookReceived::class, 4);
    }

    public function test_it_handles_a_delivery_once(): void
    {
        Event::fake();
        $fake = Eclair::fake();
        $job = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));

        $this->postEclairWebhook($job, delivery: 'whd_1')->assertNoContent();
        $this->postEclairWebhook($job, delivery: 'whd_1')->assertNoContent();
        $this->postEclairWebhook($job, delivery: 'whd_2')->assertNoContent();

        Event::assertDispatchedTimes(JobCompleted::class, 2);
    }

    public function test_a_failed_listener_lets_eclair_deliver_again(): void
    {
        $fake = Eclair::fake();
        $job = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));
        $calls = 0;
        Event::listen(JobCompleted::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('The database is down.');
            }
        });

        $this->postEclairWebhook($job, delivery: 'whd_1')->assertServerError();
        $this->postEclairWebhook($job, delivery: 'whd_1')->assertNoContent();

        $this->assertSame(2, $calls);
    }

    public function test_a_delivery_in_progress_is_refused(): void
    {
        Event::fake();
        $fake = Eclair::fake();
        $job = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));
        $lock = Cache::lock('eclair:webhook:whd_1:lock', 120);
        $lock->get();

        $this->postEclairWebhook($job, delivery: 'whd_1')->assertStatus(409);

        $lock->release();
        Event::assertNotDispatched(JobCompleted::class);
    }

    public function test_it_refuses_a_bad_signature(): void
    {
        Event::fake();
        $fake = Eclair::fake();
        $job = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));
        ['body' => $body, 'headers' => $headers] = $fake->webhook($job);
        $post = fn (string $body, array $headers) => $this->call('POST', '/eclair/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body);

        $post($body.' ', $headers)->assertUnauthorized();
        $post($body, [...$headers, 'Eclair-Signature' => Signature::sign('whsec_other', $body)])->assertUnauthorized();
        $post($body, [...$headers, 'Eclair-Signature' => Signature::sign('whsec_test', $body, time() - 301)])->assertUnauthorized();
        $post($body, array_diff_key($headers, ['Eclair-Signature' => true]))->assertUnauthorized();
        $post('{"id":"x"}', [...$headers, 'Eclair-Signature' => Signature::sign('whsec_test', '{"id":"x"}')])->assertBadRequest();

        config(['eclair.webhook.secret' => null]);
        $post($body, $headers)->assertUnauthorized()->assertJson(['detail' => 'ECLAIR_WEBHOOK_SECRET is not set.']);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_an_unknown_event_is_only_received(): void
    {
        Event::fake();
        $fake = Eclair::fake();
        $job = $fake->completeJob(Eclair::jobs()->create(self::REQUEST));

        $this->postEclairWebhook($job, 'job.archived')->assertNoContent();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->event === 'job.archived');
        Event::assertNotDispatched(JobCompleted::class);
    }
}
