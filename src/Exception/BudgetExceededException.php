<?php

declare(strict_types=1);

namespace QueryCap\Exception;

use QueryCap\BudgetViolation;

final class BudgetExceededException extends \RuntimeException
{
    public function __construct(
        public readonly BudgetViolation $violation,
    ) {
        parent::__construct(sprintf(
            'Query budget exceeded: %s (limit: %s, actual: %s)',
            $violation->reason->name,
            $violation->limit,
            $violation->actual,
        ));
    }
}
