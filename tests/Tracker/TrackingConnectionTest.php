<?php

declare(strict_types=1);

namespace QueryCap\Tests\Tracker;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\QueryTracker;
use QueryCap\Tracker\TrackingConnection;
use QueryCap\ViolationAction;

#[RequiresPhpExtension('pdo_sqlite')]
final class TrackingConnectionTest extends TestCase
{
    private \PDO $pdo;
    private QueryTracker $tracker;
    private TrackingConnection $conn;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->tracker = new QueryTracker();
        $this->conn = new TrackingConnection($this->pdo, $this->tracker);
    }

    public function test_exec_records_query(): void
    {
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->conn->exec("INSERT INTO users (name) VALUES ('Alice')");
        $summary = $scope->close();

        $this->assertSame(1, $summary->queryCount);
    }

    public function test_query_records_query(): void
    {
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $result = $this->conn->query('SELECT * FROM users');
        $this->assertInstanceOf(\PDOStatement::class, $result);

        $summary = $scope->close();
        $this->assertSame(1, $summary->queryCount);
    }

    public function test_prepare_and_execute_records_query(): void
    {
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $stmt = $this->conn->prepare('INSERT INTO users (name) VALUES (:name)');
        $this->assertNotFalse($stmt);
        $stmt->execute([':name' => 'Bob']);

        $summary = $scope->close();
        $this->assertSame(1, $summary->queryCount);
    }

    public function test_multiple_queries_counted(): void
    {
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $this->conn->exec("INSERT INTO users (name) VALUES ('Alice')");
        $this->conn->exec("INSERT INTO users (name) VALUES ('Bob')");
        $this->conn->query('SELECT * FROM users');

        $summary = $scope->close();
        $this->assertSame(3, $summary->queryCount);
    }

    public function test_fetch_works_after_tracking(): void
    {
        $this->pdo->exec("INSERT INTO users (name) VALUES ('Alice')");

        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);

        $stmt = $this->conn->prepare('SELECT name FROM users WHERE id = :id');
        $this->assertNotFalse($stmt);
        $stmt->execute([':id' => 1]);
        $name = $stmt->fetchColumn();

        $scope->close();
        $this->assertSame('Alice', $name);
    }

    public function test_last_insert_id_delegates_to_pdo(): void
    {
        $this->conn->exec("INSERT INTO users (name) VALUES ('Charlie')");

        $this->assertNotSame('0', $this->conn->lastInsertId());
    }

    public function test_no_recording_without_active_scope(): void
    {
        $this->conn->exec("INSERT INTO users (name) VALUES ('Alice')");

        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $scope = QueryScope::open($this->tracker, $budget);
        $summary = $scope->close();

        $this->assertSame(0, $summary->queryCount);
    }
}
