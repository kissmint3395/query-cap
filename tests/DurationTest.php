<?php

declare(strict_types=1);

namespace QueryCap\Tests;

use PHPUnit\Framework\TestCase;
use QueryCap\Duration;

final class DurationTest extends TestCase
{
    public function test_milliseconds_factory(): void
    {
        $d = Duration::milliseconds(500);
        $this->assertSame(500, $d->toMilliseconds());
    }

    public function test_seconds_factory(): void
    {
        $d = Duration::seconds(2);
        $this->assertSame(2000, $d->toMilliseconds());
        $this->assertSame(2.0, $d->toSeconds());
    }

    public function test_minutes_factory(): void
    {
        $d = Duration::minutes(1);
        $this->assertSame(60_000, $d->toMilliseconds());
    }

    public function test_zero(): void
    {
        $d = Duration::zero();
        $this->assertSame(0, $d->toMilliseconds());
    }

    public function test_is_greater_than(): void
    {
        $this->assertTrue(Duration::milliseconds(200)->isGreaterThan(Duration::milliseconds(100)));
        $this->assertFalse(Duration::milliseconds(100)->isGreaterThan(Duration::milliseconds(200)));
    }

    public function test_add(): void
    {
        $sum = Duration::milliseconds(300)->add(Duration::milliseconds(200));
        $this->assertSame(500, $sum->toMilliseconds());
    }
}
