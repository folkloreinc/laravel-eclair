<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

/**
 * A file written by a task. Files of the temporary storage (`eclair`) have a signed `url` until
 * they expire. A file of a group (HLS, thumbnails) reports the `count` of the other files: list
 * them all with `Eclair::jobs()->files()`.
 */
final readonly class OutputFile
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $mime,
        public int $size,
        public ?string $role = null,
        public ?int $count = null,
        public ?string $checksum = null,
        public ?string $url = null,
        public ?CarbonImmutable $expiresAt = null,
        public bool $expired = false,
        public ?string $task = null,
        private array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            mime: (string) ($data['mime'] ?? 'application/octet-stream'),
            size: (int) ($data['size'] ?? 0),
            role: isset($data['role']) ? (string) $data['role'] : null,
            count: isset($data['count']) ? (int) $data['count'] : null,
            checksum: isset($data['checksum']) ? (string) $data['checksum'] : null,
            url: isset($data['url']) ? (string) $data['url'] : null,
            expiresAt: Dates::read($data['expiresAt'] ?? null),
            expired: ($data['expired'] ?? false) === true,
            task: isset($data['task']) ? (string) $data['task'] : null,
            attributes: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
