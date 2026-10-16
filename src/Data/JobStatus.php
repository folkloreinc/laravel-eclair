<?php

namespace Folklore\Eclair\Data;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function ended(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Canceled], true);
    }
}
