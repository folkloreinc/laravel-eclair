<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

final readonly class Task
{
    /**
     * @param  list<OutputFile>  $files
     * @param  array<string, mixed>|null  $metadata  The task's `metadata` output (probe, palette…)
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $name,
        public TaskStatus $status,
        public string $op,
        public array $files = [],
        public ?array $metadata = null,
        public ?TaskError $error = null,
        public ?float $progress = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        private array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $outputs = is_array($data['outputs'] ?? null) ? $data['outputs'] : [];
        $files = is_array($outputs['files'] ?? null) ? $outputs['files'] : [];

        return new self(
            name: $name,
            status: TaskStatus::from((string) ($data['status'] ?? '')),
            op: (string) ($data['op'] ?? ''),
            files: array_values(array_map(
                fn (mixed $file): OutputFile => OutputFile::fromArray(is_array($file) ? $file : []),
                $files,
            )),
            metadata: is_array($outputs['metadata'] ?? null) ? $outputs['metadata'] : null,
            error: is_array($data['error'] ?? null) ? TaskError::fromArray($data['error']) : null,
            progress: is_numeric($data['progress'] ?? null) ? (float) $data['progress'] : null,
            startedAt: Dates::read($data['startedAt'] ?? null),
            endedAt: Dates::read($data['endedAt'] ?? null),
            attributes: $data,
        );
    }

    /** The task's file named `$name`, or its first file. */
    public function file(?string $name = null): ?OutputFile
    {
        foreach ($this->files as $file) {
            if ($name === null || $file->name === $name) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
