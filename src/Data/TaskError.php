<?php

namespace Folklore\Eclair\Data;

final readonly class TaskError
{
    public function __construct(
        public ErrorCode $code,
        public string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(ErrorCode::read((string) ($data['code'] ?? '')), (string) ($data['message'] ?? ''));
    }
}
