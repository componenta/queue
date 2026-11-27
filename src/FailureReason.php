<?php

declare(strict_types=1);

namespace Componenta\Queue;

enum FailureReason: string
{
    case Decode = 'decode';
    case Handler = 'handler';
    case Rejected = 'rejected';
    case DeliveryLimit = 'delivery_limit';
}
