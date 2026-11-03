<?php

namespace Folklore\Eclair\MediathequeV1;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\TaskStatus;
use Folklore\Eclair\Exceptions\EclairException;

/**
 * An Eclair job of a laravel-mediatheque 1.1 job didn't complete: the pipeline job fails, as with
 * the mediatheque's own jobs.
 */
class JobFailed extends EclairException
{
    public static function fromJob(Job $job): self
    {
        foreach ($job->tasks as $name => $task) {
            if ($task->status === TaskStatus::Failed && $task->error !== null) {
                return new self(sprintf('Eclair job %s: the task "%s" failed (%s): %s', $job->id, $name, $task->error->code->value, $task->error->message));
            }
        }

        return new self(sprintf('Eclair job %s ended %s.', $job->id, $job->status->value));
    }

    public static function timedOut(Job $job, int $seconds): self
    {
        return new self(sprintf('Eclair job %s did not end within %d seconds: it was canceled.', $job->id, $seconds));
    }
}
