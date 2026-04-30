<?php

declare(strict_types=1);

namespace QueryCap\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use QueryCap\Duration;
use QueryCap\QueryRecord;
use QueryCap\QueryTracker;
use QueryCap\Testing\QueryCapAssertions;

final class QueryCapAssertionsTest extends TestCase
{
    use QueryCapAssertions;

    private QueryTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new QueryTracker();
    }

    private function runNQueries(int $n, float $msEach = 1.0): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->tracker->record(new QueryRecord("SELECT {$i}", $msEach, new \DateTimeImmutable()));
        }
    }

    public function test_assert_max_queries_passes(): void
    {
        $this->assertMaxQueries(3, fn() => $this->runNQueries(2), $this->tracker);
    }

    public function test_assert_max_queries_fails_when_exceeded(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertMaxQueries(2, fn() => $this->runNQueries(5), $this->tracker);
    }

    public function test_assert_exact_queries_passes(): void
    {
        $this->assertExactQueries(3, fn() => $this->runNQueries(3), $this->tracker);
    }

    public function test_assert_exact_queries_fails_when_different(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertExactQueries(2, fn() => $this->runNQueries(3), $this->tracker);
    }

    public function test_assert_no_queries_passes(): void
    {
        $this->assertNoQueries(fn() => null, $this->tracker);
    }

    public function test_assert_no_queries_fails_when_queries_run(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertNoQueries(fn() => $this->runNQueries(1), $this->tracker);
    }

    public function test_assert_max_total_time_passes(): void
    {
        $this->assertMaxTotalTime(
            Duration::milliseconds(100),
            fn() => $this->runNQueries(3, 10.0),
            $this->tracker,
        );
    }

    public function test_assert_max_total_time_fails_when_exceeded(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertMaxTotalTime(
            Duration::milliseconds(10),
            fn() => $this->runNQueries(3, 10.0),
            $this->tracker,
        );
    }
}
