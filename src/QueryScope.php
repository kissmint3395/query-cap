<?php

declare(strict_types=1);

namespace QueryCap;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use QueryCap\Event\BudgetViolatedEvent;
use QueryCap\Event\QueryExecutedEvent;
use QueryCap\Exception\BudgetExceededException;

final class QueryScope
{
    /** @var list<QueryRecord> */
    private array $records = [];

    /** @var list<BudgetViolation> */
    private array $violations = [];

    /** @var list<ViolationReason> */
    private array $warnedReasons = [];

    private function __construct(
        private readonly QueryTracker $tracker,
        private readonly QueryCap $budget,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $dispatcher,
    ) {}

    public static function open(
        QueryTracker $tracker,
        QueryCap $budget,
        LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $dispatcher = null,
    ): self {
        $scope = new self($tracker, $budget, $logger, $dispatcher);
        $tracker->push($scope);

        return $scope;
    }

    public function record(QueryRecord $record): void
    {
        $this->records[] = $record;
        $this->dispatcher?->dispatch(new QueryExecutedEvent($record));
        $this->checkWarnAt($record);
        $this->checkBudget($record);
    }

    public function close(): QuerySummary
    {
        $this->tracker->pop($this);

        $totalTime = 0.0;
        $maxTime = 0.0;
        foreach ($this->records as $r) {
            $totalTime += $r->durationMs;
            if ($r->durationMs > $maxTime) {
                $maxTime = $r->durationMs;
            }
        }

        return new QuerySummary(
            queryCount: count($this->records),
            totalTimeMs: $totalTime,
            maxSingleQueryTimeMs: $maxTime,
            violations: $this->violations,
            records: $this->records,
        );
    }

    private function checkWarnAt(QueryRecord $lastRecord): void
    {
        $warnAt = $this->budget->warnAt;
        if ($warnAt === null) {
            return;
        }

        $factor = $warnAt / 100.0;

        $maxQ = $this->budget->maxQueries;
        if ($maxQ !== null && count($this->records) >= (int) ceil($maxQ * $factor)) {
            $this->emitWarnAt(ViolationReason::MaxQueriesExceeded, (float) count($this->records), (float) $maxQ);
        }

        $maxSingle = $this->budget->maxSingleQueryTimeMs;
        if ($maxSingle !== null && $lastRecord->durationMs >= $maxSingle * $factor) {
            $this->emitWarnAt(ViolationReason::MaxSingleQueryTimeExceeded, $lastRecord->durationMs, $maxSingle);
        }

        $maxTotal = $this->budget->maxTotalTimeMs;
        if ($maxTotal !== null) {
            $total = 0.0;
            foreach ($this->records as $r) {
                $total += $r->durationMs;
            }
            if ($total >= $maxTotal * $factor) {
                $this->emitWarnAt(ViolationReason::MaxTotalTimeExceeded, $total, $maxTotal);
            }
        }
    }

    private function emitWarnAt(ViolationReason $reason, float $actual, float $limit): void
    {
        foreach ($this->warnedReasons as $warned) {
            if ($warned === $reason) {
                return;
            }
        }

        $this->warnedReasons[] = $reason;
        $this->logger->warning('Query budget warning threshold reached', [
            'reason' => $reason->name,
            'limit' => $limit,
            'actual' => $actual,
            'threshold' => $this->budget->warnAt . '%',
        ]);
    }

    private function checkBudget(QueryRecord $lastRecord): void
    {
        $maxQ = $this->budget->maxQueries;
        if ($maxQ !== null && count($this->records) > $maxQ) {
            $this->handleViolation(new BudgetViolation(
                ViolationReason::MaxQueriesExceeded,
                (float) $maxQ,
                (float) count($this->records),
            ));
        }

        $maxSingle = $this->budget->maxSingleQueryTimeMs;
        if ($maxSingle !== null && $lastRecord->durationMs > $maxSingle) {
            $this->handleViolation(new BudgetViolation(
                ViolationReason::MaxSingleQueryTimeExceeded,
                $maxSingle,
                $lastRecord->durationMs,
            ));
        }

        $maxTotal = $this->budget->maxTotalTimeMs;
        if ($maxTotal !== null) {
            $total = 0.0;
            foreach ($this->records as $r) {
                $total += $r->durationMs;
            }
            if ($total > $maxTotal) {
                $this->handleViolation(new BudgetViolation(
                    ViolationReason::MaxTotalTimeExceeded,
                    $maxTotal,
                    $total,
                ));
            }
        }
    }

    private function handleViolation(BudgetViolation $violation): void
    {
        foreach ($this->violations as $existing) {
            if ($existing->reason === $violation->reason) {
                return;
            }
        }

        $this->violations[] = $violation;
        $this->dispatcher?->dispatch(new BudgetViolatedEvent($violation));

        match ($this->budget->onViolation) {
            ViolationAction::Log => $this->logger->warning('Query budget violated', [
                'reason' => $violation->reason->name,
                'limit' => $violation->limit,
                'actual' => $violation->actual,
            ]),
            ViolationAction::Throw => throw new BudgetExceededException($violation),
            ViolationAction::Ignore => null,
        };
    }
}
