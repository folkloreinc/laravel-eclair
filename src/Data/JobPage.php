<?php

namespace Folklore\Eclair\Data;

final readonly class JobPage
{
    /**
     * @param  list<Job>  $jobs
     */
    public function __construct(
        public array $jobs,
        public ?string $nextCursor,
    ) {}
}
