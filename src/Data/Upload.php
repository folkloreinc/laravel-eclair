<?php

namespace Folklore\Eclair\Data;

use Carbon\CarbonImmutable;

/**
 * A file sent to Eclair's temporary storage, used as an input with `['upload' => $upload->id]`.
 */
final readonly class Upload
{
    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public int $size,
        public CarbonImmutable $expiresAt,
        public bool $complete = true,
        public ?string $mime = null,
        public ?array $metadata = null,
        private array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            expiresAt: Dates::read($data['expiresAt'] ?? null) ?? CarbonImmutable::now(),
            complete: ($data['complete'] ?? true) !== false,
            mime: isset($data['mime']) ? (string) $data['mime'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
            attributes: $data,
        );
    }

    /**
     * The input of a job or a probe that reads this upload.
     *
     * @return array{upload: string}
     */
    public function input(): array
    {
        return ['upload' => $this->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
