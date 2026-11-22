<?php

namespace Folklore\Eclair\Data;

/**
 * What a task used at a paid provider (`TaskUsage` in the protocol): the seconds of media the
 * provider received, and an estimate of its cost when its price is configured. A task has it on any
 * end, completed, failed or canceled, once its provider may bill it: a transcription whose Amazon
 * job started, for example. Its attempts after an error add up; a retried job starts it again.
 */
final readonly class TaskUsage
{
    public function __construct(
        public ?float $mediaSeconds = null,
        public ?Cost $cost = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $mediaSeconds = $data['mediaSeconds'] ?? null;

        return new self(
            mediaSeconds: is_numeric($mediaSeconds) ? (float) $mediaSeconds : null,
            cost: Cost::fromArray(is_array($data['cost'] ?? null) ? $data['cost'] : null),
        );
    }

    /**
     * @return array{mediaSeconds?: float, cost?: array{amount: float, currency: string}}
     */
    public function toArray(): array
    {
        return [
            ...($this->mediaSeconds === null ? [] : ['mediaSeconds' => $this->mediaSeconds]),
            ...($this->cost === null ? [] : ['cost' => $this->cost->toArray()]),
        ];
    }
}
