<?php

declare(strict_types=1);

namespace QueryCap;

use QueryCap\Contract\QueryTrackerInterface;

final class QueryTracker implements QueryTrackerInterface
{
    /** @var list<QueryScope> */
    private array $stack = [];

    public function push(QueryScope $scope): void
    {
        $this->stack[] = $scope;
    }

    public function pop(QueryScope $scope): void
    {
        foreach ($this->stack as $key => $s) {
            if ($s === $scope) {
                array_splice($this->stack, $key, 1);
                return;
            }
        }
    }

    public function record(QueryRecord $record): void
    {
        if ($this->stack !== []) {
            $this->stack[count($this->stack) - 1]->record($record);
        }
    }

    public function hasActiveScope(): bool
    {
        return $this->stack !== [];
    }
}
