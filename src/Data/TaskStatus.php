<?php

namespace Folklore\Eclair\Data;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Skipped = 'skipped';

    public function ended(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Canceled, self::Skipped], true);
    }
}
