<?php

declare(strict_types=1);

namespace QueryCap;

final readonly class BudgetViolation
{
    public function __construct(
        public ViolationReason $reason,
        public float $limit,
        public float $actual,
    ) {}
}
