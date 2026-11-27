<?php

declare(strict_types=1);

namespace Componenta\Queue;

enum OperationOutcome: string
{
    case NotApplied = 'not_applied';
    case Indeterminate = 'indeterminate';
}
