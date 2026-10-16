<?php

namespace Folklore\Eclair\Events;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\Task;

/**
 * A task completed, for projects that subscribe to `task.completed`. The job is the whole job at
 * that time.
 */
final class TaskCompleted
{
    public function __construct(
        public readonly Job $job,
        public readonly Task $task,
        public readonly string $delivery,
    ) {}
}
