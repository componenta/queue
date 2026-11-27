<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface RetryPolicyInterface
{
    public function decide(FailureContext $failure): \Componenta\Queue\Retry\RetryAfter|\Componenta\Queue\Retry\Fail;
}
