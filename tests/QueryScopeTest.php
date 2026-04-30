<?php

declare(strict_types=1);

namespace QueryCap\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use QueryCap\BudgetViolation;
use QueryCap\Duration;
use QueryCap\Exception\BudgetExceededException;
use QueryCap\QueryCap;
use QueryCap\QueryRecord;
use QueryCap\QueryScope;
use QueryCap\QueryTracker;
use QueryCap\ViolationAction;
use QueryCap\ViolationReason;

final class QueryScopeTest extends TestCase
{
    private QueryTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new QueryTracker();
    }

    private function record(string $sql = 'SELECT 1', float $durationMs = 1.0): QueryRecord
    {
        return new QueryRecord($sql, $durationMs, new \DateTimeImmutable());
    }

    public function test_scope_records_queries(): void
    {
        $budget = QueryCap::create()->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->tracker->record($this->record('SELECT 1'));
        $this->tracker->record($this->record('SELECT 2'));

        $summary = $scope->close();

        $this->assertSame(2, $summary->queryCount);
    }

    public function test_scope_calculates_total_time(): void
    {
        $budget = QueryCap::create()->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->tracker->record($this->record('SELECT 1', 10.0));
        $this->tracker->record($this->record('SELECT 2', 5.0));

        $summary = $scope->close();

        $this->assertSame(15.0, $summary->totalTimeMs);
        $this->assertSame(10.0, $summary->maxSingleQueryTimeMs);
    }

    public function test_no_violations_when_within_budget(): void
    {
        $budget = QueryCap::create()
            ->maxQueries(5)
            ->maxTotalTime(Duration::milliseconds(100))
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record('SELECT 1', 10.0));

        $summary = $scope->close();

        $this->assertTrue($summary->isClean());
    }

    public function test_detects_max_queries_violation(): void
    {
        $budget = QueryCap::create()
            ->maxQueries(2)
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record());
        $this->tracker->record($this->record());
        $this->tracker->record($this->record());

        $summary = $scope->close();

        $this->assertTrue($summary->hasViolations());
        $this->assertSame(ViolationReason::MaxQueriesExceeded, $summary->violations[0]->reason);
        $this->assertSame(2.0, $summary->violations[0]->limit);
        $this->assertSame(3.0, $summary->violations[0]->actual);
    }

    public function test_detects_max_total_time_violation(): void
    {
        $budget = QueryCap::create()
            ->maxTotalTime(Duration::milliseconds(10))
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record('SELECT 1', 6.0));
        $this->tracker->record($this->record('SELECT 2', 6.0));

        $summary = $scope->close();

        $this->assertTrue($summary->hasViolations());
        $this->assertSame(ViolationReason::MaxTotalTimeExceeded, $summary->violations[0]->reason);
    }

    public function test_detects_max_single_query_time_violation(): void
    {
        $budget = QueryCap::create()
            ->maxSingleQueryTime(Duration::milliseconds(5))
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record('SELECT 1', 10.0));

        $summary = $scope->close();

        $this->assertTrue($summary->hasViolations());
        $this->assertSame(ViolationReason::MaxSingleQueryTimeExceeded, $summary->violations[0]->reason);
    }

    public function test_throws_on_violation_when_configured(): void
    {
        $budget = QueryCap::create()
            ->maxQueries(1)
            ->onViolation(ViolationAction::Throw)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record());

        $this->expectException(BudgetExceededException::class);
        $this->tracker->record($this->record());

        $scope->close();
    }

    public function test_violation_reason_deduplication(): void
    {
        $budget = QueryCap::create()
            ->maxQueries(1)
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget);
        $this->tracker->record($this->record());
        $this->tracker->record($this->record());
        $this->tracker->record($this->record());

        $summary = $scope->close();

        $maxQViolations = array_filter(
            $summary->violations,
            fn(BudgetViolation $v) => $v->reason === ViolationReason::MaxQueriesExceeded,
        );
        $this->assertCount(1, $maxQViolations);
    }

    public function test_tracker_stack_allows_nested_scopes(): void
    {
        $outerBudget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $innerBudget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();

        $outer = QueryScope::open($this->tracker, $outerBudget);
        $this->tracker->record($this->record('outer-1'));

        $inner = QueryScope::open($this->tracker, $innerBudget);
        $this->tracker->record($this->record('inner-1'));
        $innerSummary = $inner->close();

        $this->tracker->record($this->record('outer-2'));
        $outerSummary = $outer->close();

        $this->assertSame(1, $innerSummary->queryCount);
        $this->assertSame(2, $outerSummary->queryCount);
    }

    public function test_tracker_has_no_active_scope_after_close(): void
    {
        $budget = QueryCap::create()->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->assertTrue($this->tracker->hasActiveScope());
        $scope->close();
        $this->assertFalse($this->tracker->hasActiveScope());
    }

    public function test_summary_exposes_query_list(): void
    {
        $budget = QueryCap::create()->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->tracker->record($this->record('SELECT 1'));
        $this->tracker->record($this->record('SELECT 2'));

        $summary = $scope->close();

        $this->assertCount(2, $summary->queries());
        $this->assertSame('SELECT 1', $summary->queries()[0]->sql);
        $this->assertSame('SELECT 2', $summary->queries()[1]->sql);
    }

    public function test_summary_slow_queries_filters_by_threshold(): void
    {
        $budget = QueryCap::create()->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->tracker->record($this->record('SELECT fast', 2.0));
        $this->tracker->record($this->record('SELECT slow', 10.0));
        $this->tracker->record($this->record('SELECT also-slow', 5.0));

        $summary = $scope->close();
        $slow = $summary->slowQueries(Duration::milliseconds(5));

        $this->assertCount(2, $slow);
        $this->assertSame('SELECT slow', $slow[0]->sql);
        $this->assertSame('SELECT also-slow', $slow[1]->sql);
    }

    public function test_warn_at_fires_before_hard_limit(): void
    {
        $warnings = [];
        $logger = new class($warnings) extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = $message;
                }
            }

            /** @param list<string> $warnings */
            public function __construct(private array &$warnings) {}
        };

        $budget = QueryCap::create()
            ->maxQueries(10)
            ->warnAt(80)
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget, $logger);

        // 8 queries = ceil(10 * 0.80) → warning fires, no violation
        for ($i = 0; $i < 8; $i++) {
            $this->tracker->record($this->record());
        }

        $summary = $scope->close();

        $this->assertNotEmpty($warnings);
        $this->assertTrue($summary->isClean());
    }

    public function test_warn_at_deduplicates(): void
    {
        $warningCount = 0;
        $logger = new class($warningCount) extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->count++;
                }
            }

            public function __construct(private int &$count) {}
        };

        $budget = QueryCap::create()
            ->maxQueries(10)
            ->warnAt(50)
            ->onViolation(ViolationAction::Ignore)
            ->build();

        $scope = QueryScope::open($this->tracker, $budget, $logger);

        for ($i = 0; $i < 8; $i++) {
            $this->tracker->record($this->record());
        }

        $scope->close();

        $this->assertSame(1, $warningCount);
    }

    public function test_warn_at_not_triggered_when_null(): void
    {
        $warned = false;
        $logger = new class($warned) extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warned = true;
                }
            }

            public function __construct(private bool &$warned) {}
        };

        $budget = QueryCap::create()->maxQueries(10)->build();
        $scope = QueryScope::open($this->tracker, $budget, $logger);

        for ($i = 0; $i < 8; $i++) {
            $this->tracker->record($this->record());
        }

        $scope->close();

        $this->assertFalse($warned);
    }
}
