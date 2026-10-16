<?php

namespace Folklore\Eclair\Events;

use Folklore\Eclair\Data\Job;

/**
 * Every verified webhook, before its own event: also the events this version doesn't know.
 */
final class WebhookReceived
{
    public function __construct(
        public readonly string $event,
        public readonly Job $job,
        public readonly string $delivery,
        public readonly ?string $task = null,
    ) {}
}
