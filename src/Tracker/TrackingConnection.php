<?php

declare(strict_types=1);

namespace QueryCap\Tracker;

use QueryCap\Contract\QueryTrackerInterface;
use QueryCap\QueryRecord;

/**
 * Wraps PDO to record query execution time via QueryTrackerInterface.
 * Use this in place of PDO wherever you want query tracking.
 */
final class TrackingConnection
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly QueryTrackerInterface $tracker,
    ) {}

    public function query(string $sql): \PDOStatement|false
    {
        $start = hrtime(true);
        $stmt = $this->pdo->query($sql);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if ($stmt !== false) {
            $this->tracker->record(new QueryRecord(
                sql: $sql,
                durationMs: $durationMs,
                executedAt: new \DateTimeImmutable(),
            ));
        }

        return $stmt;
    }

    public function exec(string $sql): int|false
    {
        $start = hrtime(true);
        $result = $this->pdo->exec($sql);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if ($result !== false) {
            $this->tracker->record(new QueryRecord(
                sql: $sql,
                durationMs: $durationMs,
                executedAt: new \DateTimeImmutable(),
            ));
        }

        return $result;
    }

    /** @param array<int|string, mixed> $options */
    public function prepare(string $sql, array $options = []): TrackingStatement|false
    {
        $stmt = $this->pdo->prepare($sql, $options);
        if ($stmt === false) {
            return false;
        }

        return new TrackingStatement($stmt, $sql, $this->tracker);
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->pdo->lastInsertId($name);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        return $this->pdo->quote($string, $type);
    }

    public function errorCode(): ?string
    {
        return $this->pdo->errorCode();
    }

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return $this->pdo->errorInfo();
    }
}
