<?php

declare(strict_types=1);

namespace QueryCap\Testing;

use QueryCap\Duration;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\QuerySummary;
use QueryCap\QueryTracker;
use QueryCap\ViolationAction;

/**
 * PHPUnit trait for asserting query budget constraints.
 *
 * Usage:
 *   use QueryCapAssertions;
 *   $this->assertMaxQueries(3, fn() => $service->list(), $tracker);
 */
trait QueryCapAssertions
{
    private function captureQuerySummary(QueryTracker $tracker, callable $fn): QuerySummary
    {
        $budget = QueryCap::create()
            ->maxQueries(PHP_INT_MAX)
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($tracker, $budget);

        try {
            $fn();
        } finally {
            $summary = $scope->close();
        }

        return $summary;
    }

    public function assertMaxQueries(int $max, callable $fn, QueryTracker $tracker): void
    {
        $summary = $this->captureQuerySummary($tracker, $fn);

        $this->assertLessThanOrEqual(
            $max,
            $summary->queryCount,
            sprintf('Expected at most %d queries, but %d were executed.', $max, $summary->queryCount),
        );
    }

    public function assertExactQueries(int $expected, callable $fn, QueryTracker $tracker): void
    {
        $summary = $this->captureQuerySummary($tracker, $fn);

        $this->assertSame(
            $expected,
            $summary->queryCount,
            sprintf('Expected exactly %d queries, but %d were executed.', $expected, $summary->queryCount),
        );
    }

    public function assertMaxTotalTime(Duration $maxDuration, callable $fn, QueryTracker $tracker): void
    {
        $summary = $this->captureQuerySummary($tracker, $fn);
        $limitMs = (float) $maxDuration->toMilliseconds();

        $this->assertLessThanOrEqual(
            $limitMs,
            $summary->totalTimeMs,
            sprintf('Expected total query time <= %sms, but was %sms.', $limitMs, $summary->totalTimeMs),
        );
    }

    public function assertNoQueries(callable $fn, QueryTracker $tracker): void
    {
        $this->assertExactQueries(0, $fn, $tracker);
    }
}
