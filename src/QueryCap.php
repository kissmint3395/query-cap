<?php

declare(strict_types=1);

namespace QueryCap;

final class QueryCap
{
    public function __construct(
        public readonly ?int $maxQueries,
        public readonly ?float $maxTotalTimeMs,
        public readonly ?float $maxSingleQueryTimeMs,
        public readonly ViolationAction $onViolation,
        public readonly ?int $warnAt = null,
    ) {}

    public static function create(): QueryCapBuilder
    {
        return new QueryCapBuilder();
    }
}
