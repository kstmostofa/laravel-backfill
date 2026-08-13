<?php

namespace Kstmostofa\Backfill\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    /**
     * A run in one of these states holds a cursor that can be picked back up.
     */
    public function isResumable(): bool
    {
        return in_array($this, [self::Paused, self::Interrupted, self::Failed, self::Pending], true);
    }

    /**
     * A run in one of these states is finished and will never advance again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
