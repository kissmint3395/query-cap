<?php

declare(strict_types=1);

namespace QueryCap\Tests;

use PHPUnit\Framework\TestCase;
use QueryCap\Duration;
use QueryCap\QueryCap;
use QueryCap\ViolationAction;

final class QueryCapBuilderTest extends TestCase
{
    public function test_build_defaults(): void
    {
        $budget = QueryCap::create()->build();

        $this->assertNull($budget->maxQueries);
        $this->assertNull($budget->maxTotalTimeMs);
        $this->assertNull($budget->maxSingleQueryTimeMs);
        $this->assertSame(ViolationAction::Log, $budget->onViolation);
    }

    public function test_max_queries(): void
    {
        $budget = QueryCap::create()->maxQueries(10)->build();
        $this->assertSame(10, $budget->maxQueries);
    }

    public function test_max_total_time(): void
    {
        $budget = QueryCap::create()->maxTotalTime(Duration::milliseconds(200))->build();
        $this->assertSame(200.0, $budget->maxTotalTimeMs);
    }

    public function test_max_single_query_time(): void
    {
        $budget = QueryCap::create()->maxSingleQueryTime(Duration::milliseconds(50))->build();
        $this->assertSame(50.0, $budget->maxSingleQueryTimeMs);
    }

    public function test_on_violation(): void
    {
        $budget = QueryCap::create()->onViolation(ViolationAction::Throw)->build();
        $this->assertSame(ViolationAction::Throw, $budget->onViolation);
    }

    public function test_builder_is_immutable(): void
    {
        $builder = QueryCap::create();
        $builder->maxQueries(5);

        $this->assertNull($builder->build()->maxQueries);
    }

    public function test_warn_at(): void
    {
        $budget = QueryCap::create()->warnAt(80)->build();
        $this->assertSame(80, $budget->warnAt);
    }

    public function test_warn_at_defaults_to_null(): void
    {
        $budget = QueryCap::create()->build();
        $this->assertNull($budget->warnAt);
    }

    public function test_warn_at_rejects_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueryCap::create()->warnAt(0);
    }

    public function test_warn_at_rejects_100(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QueryCap::create()->warnAt(100);
    }
}
