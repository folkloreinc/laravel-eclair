<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

/**
 * A job, as `GET /v1/jobs/{id}` and the webhooks return it. `toArray()` gives the whole response,
 * including the fields this class doesn't read. `preset` and `presetVersion` are the preset the job
 * used, at the version it had then.
 */
final readonly class Job
{
    /**
     * @param  array<string, Task>  $tasks
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public JobStatus $status,
        public array $tasks,
        public array $metadata,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?string $preset = null,
        public ?int $presetVersion = null,
        private array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $preset = is_array($data['preset'] ?? null) ? $data['preset'] : [];
        $tasks = [];
        foreach (is_array($data['tasks'] ?? null) ? $data['tasks'] : [] as $name => $task) {
            $tasks[(string) $name] = Task::fromArray((string) $name, is_array($task) ? $task : []);
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            status: JobStatus::from((string) ($data['status'] ?? '')),
            tasks: $tasks,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            createdAt: Dates::read($data['createdAt'] ?? null) ?? CarbonImmutable::now(),
            startedAt: Dates::read($data['startedAt'] ?? null),
            endedAt: Dates::read($data['endedAt'] ?? null),
            preset: isset($preset['name']) ? (string) $preset['name'] : null,
            presetVersion: isset($preset['version']) ? (int) $preset['version'] : null,
            attributes: $data,
        );
    }

    public function ended(): bool
    {
        return $this->status->ended();
    }

    public function task(string $name): ?Task
    {
        return $this->tasks[$name] ?? null;
    }

    /**
     * The files of every task, with their task's name.
     *
     * @return list<OutputFile>
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->tasks as $task) {
            foreach ($task->files as $file) {
                $files[] = OutputFile::fromArray([...$file->toArray(), 'task' => $task->name]);
            }
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
