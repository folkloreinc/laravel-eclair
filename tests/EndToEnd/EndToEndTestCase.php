<?php

namespace Folklore\Eclair\Tests\EndToEnd;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Facades\Eclair;
use Folklore\Eclair\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;

/**
 * Runs the client against a real Eclair server, started by `tests/EndToEnd/eclair.sh`. The tests are
 * skipped when `ECLAIR_E2E_URL` is unset.
 */
abstract class EndToEndTestCase extends TestCase
{
    protected const FIXTURE = __DIR__.'/fixtures/image.png';

    protected function setUp(): void
    {
        if (self::env('ECLAIR_E2E_URL') === null) {
            $this->markTestSkipped('ECLAIR_E2E_URL is not set: start Eclair with tests/EndToEnd/eclair.sh.');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('eclair.url', self::env('ECLAIR_E2E_URL'));
            $config->set('eclair.key', self::env('ECLAIR_E2E_KEY'));
            $config->set('eclair.webhook.secret', self::env('ECLAIR_E2E_WEBHOOK_SECRET'));
            $config->set('eclair.webhook.url', null);
        });
    }

    /**
     * Polls the job until it ends.
     */
    protected function waitFor(Job $job, int $seconds = 60): Job
    {
        $deadline = microtime(true) + $seconds;
        while (! $job->ended()) {
            if (microtime(true) > $deadline) {
                $this->fail(sprintf('%s did not end within %d seconds: %s', $job->id, $seconds, json_encode($job->toArray())));
            }
            usleep(250_000);
            $job = Eclair::jobs()->find($job->id);
        }

        return $job;
    }

    protected static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
