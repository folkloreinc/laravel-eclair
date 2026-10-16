<?php

namespace Folklore\Eclair\Events;

use Folklore\Eclair\Data\Job;

final class JobFailed
{
    public function __construct(
        public readonly Job $job,
        public readonly string $delivery,
    ) {}
}
