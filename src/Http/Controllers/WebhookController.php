<?php

namespace Folklore\Eclair\Http\Controllers;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Events\JobCanceled;
use Folklore\Eclair\Events\JobCompleted;
use Folklore\Eclair\Events\JobFailed;
use Folklore\Eclair\Events\TaskCompleted;
use Folklore\Eclair\Events\WebhookReceived;
use Folklore\Eclair\Http\Problem;
use Folklore\Eclair\Support\Signature;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use ValueError;

/**
 * Receives Eclair's webhooks: checks the signature, ignores deliveries it already handled, and
 * dispatches `WebhookReceived`, then `JobCompleted`, `JobFailed`, `JobCanceled` or
 * `TaskCompleted`. A listener that throws makes the request fail, and Eclair delivers it again.
 */
final class WebhookController
{
    /** How long a delivery is remembered: Eclair retries for 24 hours. */
    private const REMEMBER = 48 * 3600;

    public function __invoke(Request $request, Config $config, Cache $cache, Dispatcher $events): SymfonyResponse
    {
        $secret = (string) $config->get('eclair.webhook.secret');
        $body = $request->getContent();
        if (! Signature::verify(
            $request->header('Eclair-Signature'),
            $secret,
            $body,
            (int) $config->get('eclair.webhook.tolerance', Signature::TOLERANCE),
        )) {
            return Problem::response(401, 'Invalid signature', $secret === '' ? 'ECLAIR_WEBHOOK_SECRET is not set.' : null);
        }

        $event = (string) $request->header('Eclair-Event');
        $delivery = (string) $request->header('Eclair-Delivery');
        $data = json_decode($body, true);
        if ($event === '' || $delivery === '' || ! is_array($data)) {
            return Problem::response(400, 'Not a webhook of Eclair');
        }
        try {
            $job = Job::fromArray($data);
        } catch (ValueError) {
            return Problem::response(400, 'Not a job of Eclair');
        }

        $store = $cache->store($config->get('eclair.webhook.cache'));
        $key = 'eclair:webhook:'.$delivery;
        // Another request handles the same delivery: Eclair delivers it again later.
        $locks = $store->getStore();
        if (! $locks instanceof LockProvider) {
            throw new LogicException('The cache store of eclair.webhook.cache must support locks.');
        }
        $lock = $locks->lock($key.':lock', 120);
        if (! $lock->get()) {
            return Problem::response(409, 'Delivery in progress');
        }
        try {
            if ($store->has($key)) {
                return new Response(null, 204);
            }
            $task = $request->header('Eclair-Task');
            $task = is_string($task) && $task !== '' ? $task : null;
            $events->dispatch(new WebhookReceived($event, $job, $delivery, $task));
            $completed = $task === null ? null : $job->task($task);
            $specific = match ($event) {
                'job.completed' => new JobCompleted($job, $delivery),
                'job.failed' => new JobFailed($job, $delivery),
                'job.canceled' => new JobCanceled($job, $delivery),
                'task.completed' => $completed === null ? null : new TaskCompleted($job, $completed, $delivery),
                default => null,
            };
            if ($specific !== null) {
                $events->dispatch($specific);
            }
            $store->put($key, true, self::REMEMBER);
        } finally {
            $lock->release();
        }

        return new Response(null, 204);
    }
}
