<?php

namespace Folklore\Eclair\Events;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\Task;

/**
 * A running task progressed, for projects that subscribe to `task.progress`. The job is the whole
 * job at that time. Deliveries are attempted once and can arrive out of order, after the task
 * completed included: keep the highest progress, and ignore it for a task that has ended.
 */
final class TaskProgressed
{
    public function __construct(
        public readonly Job $job,
        public readonly Task $task,
        public readonly string $delivery,
    ) {}
}
