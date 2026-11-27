<?php

declare(strict_types=1);

namespace Componenta\Queue;

enum CountAccuracy: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case Unknown = 'unknown';
}
