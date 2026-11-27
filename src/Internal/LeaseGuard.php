<?php

declare(strict_types=1);

namespace Componenta\Queue\Internal;

use Throwable;

/** @internal Per-step state; never passed to an application handler. */
final class LeaseGuard
{
    public bool $active = true;
    public ?Throwable $failure = null;
}
