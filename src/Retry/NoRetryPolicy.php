<?php

declare(strict_types=1);

namespace Componenta\Queue\Retry;

use Componenta\Queue\FailureContext;
use Componenta\Queue\RetryPolicyInterface;
use Override;

final readonly class NoRetryPolicy implements RetryPolicyInterface
{
    #[Override]
    public function decide(FailureContext $failure): Fail
    {
        return new Fail();
    }
}
