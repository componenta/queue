<?php

declare(strict_types=1);

namespace Componenta\Queue;

enum ProcessingResult: string
{
    case Idle = 'idle';
    case Acknowledged = 'acknowledged';
    case RetryScheduled = 'retry_scheduled';
    case Failed = 'failed';
}
