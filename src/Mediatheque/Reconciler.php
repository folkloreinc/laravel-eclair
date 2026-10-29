<?php

namespace Folklore\Eclair\Mediatheque;

use Folklore\Eclair\Data\Job;
use Folklore\Eclair\Data\Task;
use Folklore\Eclair\Data\TaskStatus;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;

/**
 * Reports an Eclair job to the mediatheque's recorder, task by task. Every webhook brings the
 * whole job, in any order and maybe twice: the recorder ignores what would move a rendition back,
 * and renditions that already ended are left alone.
 *
 * @internal
 */
class Reconciler
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Filesystems $filesystems,
    ) {}

    public function reconcile(PipelineRun $run, Job $job): void
    {
        foreach ($run->jobs() as $pipelineJob) {
            if ($pipelineJob->status()->isFinal()) {
                continue;
            }
            $task = $job->task($pipelineJob->rendition()->name);
            if ($task !== null) {
                $this->task($run, $pipelineJob, $task);
            }
        }
    }

    private function task(PipelineRun $run, PipelineJob $pipelineJob, Task $task): void
    {
        match ($task->status) {
            TaskStatus::Running => $this->running($pipelineJob, $task),
            TaskStatus::Completed => $this->recorder->completed($pipelineJob, $this->outputs($run, $pipelineJob, $task)),
            TaskStatus::Failed => $this->recorder->failed($pipelineJob, TranscoderError::fromDriver(
                $task->error?->code->value,
                $task->error === null ? 'The task failed in Eclair.' : $task->error->message,
            )),
            TaskStatus::Skipped => $this->recorder->skipped($pipelineJob, 'Eclair skipped it: its input didn\'t match its condition, or the task it reads from was skipped.'),
            TaskStatus::Canceled => $this->recorder->canceled($pipelineJob),
            TaskStatus::Pending, TaskStatus::Queued => null,
        };
    }

    private function running(PipelineJob $pipelineJob, Task $task): void
    {
        $this->recorder->started($pipelineJob);
        if ($task->progress !== null) {
            $this->recorder->progress($pipelineJob, $task->progress);
        }
    }

    private function outputs(PipelineRun $run, PipelineJob $pipelineJob, Task $task): Outputs
    {
        if ($run->name() === PipelineDefinition::PROBE) {
            return new Outputs(metadata: ProbeMetadata::canonical($task->metadata ?? []));
        }
        $rendition = $pipelineJob->rendition();
        // An intermediate rendition: its files are deleted, and its metadata describes them
        if (! $rendition->keep) {
            return Outputs::none();
        }

        $metadata = $task->metadata ?? [];
        $group = count($task->files) > 1;
        $files = [];
        foreach ($task->files as $index => $file) {
            $files[] = new OutputFile(
                path: $file->path,
                index: $group ? $index : null,
                mime: $file->mime,
                size: $file->size,
                checksum: $file->checksum,
                width: self::integer($metadata['width'] ?? null),
                height: self::integer($metadata['height'] ?? null),
                duration: is_int($metadata['duration'] ?? null) || is_float($metadata['duration'] ?? null) ? (float) $metadata['duration'] : null,
                bitrate: self::integer($metadata['bitrate'] ?? null),
                partsCount: $file->count,
            );
        }

        // Eclair writes its files private: public runs make them public, as the ffmpeg driver writes them
        if ($run->visibility() === Visibility::Public) {
            $disk = $this->filesystems->disk($run->outputSource());
            foreach ($files as $file) {
                $disk->setVisibility($file->path, Visibility::Public->value);
            }
        }

        return new Outputs($files, $metadata);
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_float($value) && floor($value) === $value) ? (int) $value : null;
    }
}
