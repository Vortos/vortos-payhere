<?php

declare(strict_types=1);

namespace Vortos\PayHere\Failover;

enum PayHereCircuitBreakerState: string
{
    case Closed   = 'closed';
    case Open     = 'open';
    case HalfOpen = 'half_open';
}
