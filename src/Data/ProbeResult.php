<?php

namespace Folklore\Eclair\Data;

/**
 * The result of `Eclair::probe()`: the metadata, or, when the probe took longer than its timeout,
 * the job that brings it later (in its `probe` task and its webhook).
 */
final readonly class ProbeResult
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ?array $metadata,
        public ?Job $job = null,
    ) {}

    public function pending(): bool
    {
        return $this->metadata === null;
    }
}
