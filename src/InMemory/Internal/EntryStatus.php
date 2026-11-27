<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

/** @internal */
enum EntryStatus
{
    case Ready;
    case Leased;
    case Failed;
    case Completed;
    case Discarded;
}
