<?php

declare(strict_types=1);

namespace Componenta\Queue;

enum FailureStatus: string
{
    case Pending = 'pending';
    case Redriven = 'redriven';
    case Discarded = 'discarded';
}
