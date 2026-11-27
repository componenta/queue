<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

/** @internal */
enum LocalStatus
{
    case Active;
    case Settled;
    case Lost;
    case Closed;
}
