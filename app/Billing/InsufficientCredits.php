<?php

namespace App\Billing;

use App\Support\Usd;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $requiredNano, public readonly int $availableNano)
    {
        parent::__construct(sprintf(
            'This generation can cost up to %s but you only have %s available.',
            Usd::format($requiredNano, 4),
            Usd::format($availableNano, 4),
        ));
    }
}
