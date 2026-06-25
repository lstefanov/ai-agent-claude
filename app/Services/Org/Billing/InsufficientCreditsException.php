<?php

namespace App\Services\Org\Billing;

use RuntimeException;

/**
 * Хвърля се от CreditMeterService::reserve, когато балансът не покрива оценката.
 * Контролерите я хващат → 402 + upsell; scheduled тиковете → пропускат + известие.
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $needed,
        public readonly int $available,
    ) {
        parent::__construct("Недостатъчно кредити (нужни {$needed}, налични {$available}).");
    }
}
