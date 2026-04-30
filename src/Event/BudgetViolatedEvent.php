<?php

declare(strict_types=1);

namespace QueryCap\Event;

use QueryCap\BudgetViolation;

final readonly class BudgetViolatedEvent
{
    public function __construct(
        public BudgetViolation $violation,
    ) {}
}
