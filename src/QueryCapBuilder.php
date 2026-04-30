<?php

declare(strict_types=1);

namespace QueryCap;

final class QueryCapBuilder
{
    private ?int $maxQueries = null;
    private ?float $maxTotalTimeMs = null;
    private ?float $maxSingleQueryTimeMs = null;
    private ViolationAction $onViolation = ViolationAction::Log;
    private ?int $warnAt = null;

    public function maxQueries(int $max): static
    {
        $clone = clone $this;
        $clone->maxQueries = $max;

        return $clone;
    }

    public function maxTotalTime(Duration $duration): static
    {
        $clone = clone $this;
        $clone->maxTotalTimeMs = (float) $duration->toMilliseconds();

        return $clone;
    }

    public function maxSingleQueryTime(Duration $duration): static
    {
        $clone = clone $this;
        $clone->maxSingleQueryTimeMs = (float) $duration->toMilliseconds();

        return $clone;
    }

    public function onViolation(ViolationAction $action): static
    {
        $clone = clone $this;
        $clone->onViolation = $action;

        return $clone;
    }

    public function warnAt(int $percent): static
    {
        if ($percent < 1 || $percent > 99) {
            throw new \InvalidArgumentException('warnAt must be between 1 and 99.');
        }
        $clone = clone $this;
        $clone->warnAt = $percent;

        return $clone;
    }

    public function build(): QueryCap
    {
        return new QueryCap(
            maxQueries: $this->maxQueries,
            maxTotalTimeMs: $this->maxTotalTimeMs,
            maxSingleQueryTimeMs: $this->maxSingleQueryTimeMs,
            onViolation: $this->onViolation,
            warnAt: $this->warnAt,
        );
    }
}
