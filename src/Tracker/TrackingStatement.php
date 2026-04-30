<?php

declare(strict_types=1);

namespace QueryCap\Tracker;

use QueryCap\Contract\QueryTrackerInterface;
use QueryCap\QueryRecord;

/**
 * Wraps PDOStatement to record query execution time via QueryTrackerInterface.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class TrackingStatement implements \IteratorAggregate
{
    public readonly string $queryString;

    public function __construct(
        private readonly \PDOStatement $statement,
        private readonly string $sql,
        private readonly QueryTrackerInterface $tracker,
    ) {
        $this->queryString = $statement->queryString;
    }

    /** @param array<string|int, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        $result = $this->statement->execute($params);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->tracker->record(new QueryRecord(
            sql: $this->sql,
            durationMs: $durationMs,
            executedAt: new \DateTimeImmutable(),
        ));

        return $result;
    }

    public function fetch(
        int $mode = \PDO::FETCH_DEFAULT,
        int $cursorOrientation = \PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return $this->statement->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    /** @return list<mixed> */
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        /** @var list<mixed> */
        return $this->statement->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->statement->fetchColumn($column);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<mixed> $constructorArgs
     * @return T|false
     */
    public function fetchObject(string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        /** @var T|false */
        return $this->statement->fetchObject($class, $constructorArgs);
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        return $this->statement->bindValue($param, $value, $type);
    }

    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = \PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        return $this->statement->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function bindColumn(
        string|int $column,
        mixed &$var,
        int $type = \PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        return $this->statement->bindColumn($column, $var, $type, $maxLength, $driverOptions);
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    public function closeCursor(): bool
    {
        return $this->statement->closeCursor();
    }

    public function errorCode(): ?string
    {
        return $this->statement->errorCode();
    }

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return $this->statement->errorInfo();
    }

    public function getAttribute(int $name): mixed
    {
        return $this->statement->getAttribute($name);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->statement->setAttribute($attribute, $value);
    }

    public function nextRowset(): bool
    {
        return $this->statement->nextRowset();
    }

    public function debugDumpParams(): void
    {
        $this->statement->debugDumpParams();
    }

    public function getIterator(): \Traversable
    {
        return $this->statement;
    }
}
