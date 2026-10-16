<?php

namespace Folklore\Eclair\Testing;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Eclair;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * For an application's tests, with `Eclair::fake()`: sends a signed webhook to the application's
 * route, as Eclair does when a job ends.
 *
 * @mixin TestCase
 */
trait InteractsWithEclair
{
    /**
     * @return TestResponse<Response>
     */
    protected function postEclairWebhook(Job|string $job, ?string $event = null, ?string $task = null, ?string $delivery = null): TestResponse
    {
        $fake = $this->app->make(Eclair::class);
        if (! $fake instanceof EclairFake) {
            throw new LogicException('Call Eclair::fake() before sending a webhook.');
        }
        ['body' => $body, 'headers' => $headers] = $fake->webhook($job, $event, $task, $delivery);

        return $this->call('POST', route('eclair.webhook'), [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }
}
