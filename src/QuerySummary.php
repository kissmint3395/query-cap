<?php

declare(strict_types=1);

namespace QueryCap;

final readonly class QuerySummary
{
    /**
     * @param list<BudgetViolation> $violations
     * @param list<QueryRecord> $records
     */
    public function __construct(
        public int $queryCount,
        public float $totalTimeMs,
        public float $maxSingleQueryTimeMs,
        public array $violations,
        private array $records = [],
    ) {}

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function isClean(): bool
    {
        return $this->violations === [];
    }

    /** @return list<QueryRecord> */
    public function queries(): array
    {
        return $this->records;
    }

    /** @return list<QueryRecord> */
    public function slowQueries(Duration $threshold): array
    {
        return array_values(array_filter(
            $this->records,
            fn(QueryRecord $r) => $r->durationMs >= $threshold->toMilliseconds(),
        ));
    }
}
