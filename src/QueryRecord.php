<?php

declare(strict_types=1);

namespace QueryCap;

final readonly class QueryRecord
{
    public function __construct(
        public string $sql,
        public float $durationMs,
        public \DateTimeImmutable $executedAt,
    ) {}
}
