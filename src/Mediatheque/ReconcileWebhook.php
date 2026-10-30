<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Events\WebhookReceived;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Reports the webhooks of the mediatheque's jobs to its recorder: pipeline runs, found by the key
 * in the job's `metadata`, and pending probes, found by the job's id.
 *
 * Every probe is a job, and its webhook comes even when Eclair answered the probe with its result:
 * a probe job that ended within the probe's timeout has nothing waiting for it. A longer one answered
 * `202`, and its webhook can arrive before the mediatheque recorded the job's id: the listener throws,
 * the webhook fails, and Eclair delivers it again later.
 */
class ReconcileWebhook
{
    /** Seconds allowed for the clocks of Eclair's API and workers, which date the job. */
    private const int CLOCK_MARGIN = 1;

    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly Config $config,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $job = $event->job;
        $mediatheque = $job->metadata['mediatheque'] ?? null;
        if (is_array($mediatheque) && is_string($mediatheque['run'] ?? null)) {
            // A run that doesn't exist any more (its media was deleted) has nothing to record
            $run = Runs::find($mediatheque['run']);
            if ($run !== null) {
                $this->reconciler->reconcile($run, $job);
            }

            return;
        }

        if ($this->config->get('eclair.mediatheque.prober') !== true || ! $job->ended() || array_keys($job->tasks) !== ['probe']) {
            return;
        }
        $run = Runs::probe($job->id);
        if ($run === null) {
            if ($this->answeredInTime($job)) {
                return;
            }

            throw new RuntimeException(sprintf('No probe of the mediatheque waits for the Eclair job %s yet.', $job->id));
        }
        $this->reconciler->reconcile($run, $job);
    }

    /**
     * Whether the probe ended before Eclair stopped waiting for it, so it answered the request with
     * the result instead of the job.
     */
    private function answeredInTime(Job $job): bool
    {
        return $job->endedAt !== null
            && $job->createdAt->diffInSeconds($job->endedAt) < EclairProber::timeout($this->config) - self::CLOCK_MARGIN;
    }
}
