# QueryCap

A PHP 8.2+ library for tracking and enforcing SQL query budgets per request.

Catch query explosions in development, enforce limits in production, and assert query counts in tests — all without a framework dependency.

## Why

- You deploy a feature and 1 request starts running 200 queries. You find out in production.
- You have no way to assert "this service method must run at most 3 queries" in a test.
- Existing profiling tools (Clockwork, Debugbar) are dev-only and framework-specific.

QueryCap solves all three.

## Features

- **Framework-agnostic** — works with any PDO-based stack
- **Dev + production ready** — log, throw, or ignore violations
- **PHPUnit assertions** — `assertMaxQueries`, `assertExactQueries`, `assertNoQueries`
- **PSR-15 middleware** — automatic per-request scope management
- **PSR-14 events** — subscribe to `QueryExecutedEvent` / `BudgetViolatedEvent`
- **PHPStan level 8** — fully typed

## Installation

```bash
composer require kissmint3395/query-cap
```

## Quick start

### 1. Wrap your PDO connection

```php
use QueryCap\QueryTracker;
use QueryCap\Tracker\TrackingConnection;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$tracker = new QueryTracker();
$db = new TrackingConnection($pdo, $tracker);

// Use $db exactly like PDO
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => 1]);
```

### 2. Open a scope and enforce a budget

```php
use QueryCap\Duration;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\ViolationAction;

$budget = QueryCap::create()
    ->maxQueries(50)
    ->maxTotalTime(Duration::milliseconds(200))
    ->onViolation(ViolationAction::Log)   // Log | Throw | Ignore
    ->build();

$scope = QueryScope::open($tracker, $budget, $logger);

// ... handle request ...

$summary = $scope->close();
echo $summary->queryCount;    // number of queries run
echo $summary->totalTimeMs;   // total execution time
echo $summary->hasViolations(); // true if any limit was exceeded
```

### 3. PSR-15 middleware (automatic scope per request)

```php
use QueryCap\Middleware\QueryCapMiddleware;

$app->add(new QueryCapMiddleware(
    tracker: $tracker,
    budget:  $budget,
    logger:  $logger,   // PSR-3, optional
));
```

### 4. PHPUnit assertions

```php
use QueryCap\Testing\QueryCapAssertions;

final class UserServiceTest extends TestCase
{
    use QueryCapAssertions;

    public function test_list_runs_at_most_3_queries(): void
    {
        $this->assertMaxQueries(3, fn() => $this->service->list(), $this->tracker);
    }

    public function test_find_runs_exactly_1_query(): void
    {
        $this->assertExactQueries(1, fn() => $this->service->find(1), $this->tracker);
    }

    public function test_cached_result_runs_no_queries(): void
    {
        $this->assertNoQueries(fn() => $this->service->findCached(1), $this->tracker);
    }
}
```

## Violation actions

| Action | Behavior |
|---|---|
| `ViolationAction::Log` | Logs a PSR-3 warning (default) |
| `ViolationAction::Throw` | Throws `BudgetExceededException` |
| `ViolationAction::Ignore` | Records the violation silently |

## Budget options

```php
QueryCap::create()
    ->maxQueries(50)                                  // max number of queries
    ->maxTotalTime(Duration::milliseconds(200))        // max cumulative time
    ->maxSingleQueryTime(Duration::milliseconds(50))  // max time for a single query
    ->warnAt(80)                                      // warn at 80% of each limit (1–99)
    ->onViolation(ViolationAction::Log)
    ->build();
```

`warnAt` emits a PSR-3 warning before the hard limit is reached. For example, with `maxQueries(50)->warnAt(80)`, a warning fires at 40 queries — leaving headroom to investigate before enforcement kicks in.

## Inspecting queries

`QueryScope::close()` returns a `QuerySummary` that exposes the recorded queries:

```php
$summary = $scope->close();

// All queries recorded in this scope
foreach ($summary->queries() as $record) {
    echo $record->sql;         // SQL string
    echo $record->durationMs;  // execution time in milliseconds
    echo $record->executedAt;  // DateTimeImmutable
}

// Only queries that exceeded a threshold
$slow = $summary->slowQueries(Duration::milliseconds(50));
```

## PSR-14 events

Subscribe to query events via any PSR-14 event dispatcher:

```php
// Fired for every query
QueryCap\Event\QueryExecutedEvent::class

// Fired when a budget limit is exceeded
QueryCap\Event\BudgetViolatedEvent::class
```

## Nested scopes

Scopes can be nested. Each scope tracks only queries executed while it is active:

```php
$outer = QueryScope::open($tracker, $budget);

    $inner = QueryScope::open($tracker, $budget);
    // ... some queries ...
    $innerSummary = $inner->close();  // tracks inner queries only

$outerSummary = $outer->close();      // tracks outer queries only
```

## Integration with Aegis

QueryCap pairs naturally with [Aegis](https://github.com/kissmint3395/aegis) for resilience:

```php
use Aegis\ResiliencePipeline;

$pipeline = ResiliencePipeline::builder()
    ->timeout(Duration::seconds(5))
    ->circuitBreaker('db', failureThreshold: 5)
    ->build();

// QueryCap measures slow queries → Aegis circuit breaker opens when DB is degraded
$scope = QueryScope::open($tracker, $budget);
$pipeline->execute(fn() => $db->query('SELECT ...'));
$scope->close();
```

## Requirements

- PHP 8.2+
- `psr/log` ^3.0
- `psr/event-dispatcher` ^1.0
- `psr/http-server-middleware` ^1.0

## License

MIT

---

## QueryCap（日本語ドキュメント）

PHP 8.2+ 向けの、リクエストごとに SQL クエリ数・実行時間を追跡・制限するライブラリです。

開発中はクエリ爆発を検知し、本番環境では上限を強制し、テストではクエリ数をアサートできます。フレームワーク依存なし。

## なぜ必要か

- 新機能をデプロイしたら 1 リクエストで 200 クエリが走っていた。気づいたのは本番。
- 「このサービスメソッドは最大 3 クエリ」とテストで保証する手段がない。
- Clockwork・Debugbar などの既存ツールは開発環境専用かつフレームワーク固有。

QueryCap はこの 3 つをまとめて解決します。

## 機能

- **フレームワーク非依存** — PDO ベースのスタックならどこでも動作
- **開発・本番の両対応** — 違反時にログ出力・例外スロー・無視を選択可能
- **PHPUnit アサーション** — `assertMaxQueries` / `assertExactQueries` / `assertNoQueries`
- **PSR-15 ミドルウェア** — リクエストごとのスコープを自動管理
- **PSR-14 イベント** — `QueryExecutedEvent` / `BudgetViolatedEvent` を購読可能
- **PHPStan level 8** — 完全な型付き実装

## インストール

```bash
composer require kissmint3395/query-cap
```

## クイックスタート

### 1. PDO 接続をラップする

```php
use QueryCap\QueryTracker;
use QueryCap\Tracker\TrackingConnection;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$tracker = new QueryTracker();
$db = new TrackingConnection($pdo, $tracker);

// PDO と同じように使える
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => 1]);
```

### 2. スコープを開いてバジェットを適用する

```php
use QueryCap\Duration;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\ViolationAction;

$budget = QueryCap::create()
    ->maxQueries(50)
    ->maxTotalTime(Duration::milliseconds(200))
    ->onViolation(ViolationAction::Log)   // Log | Throw | Ignore
    ->build();

$scope = QueryScope::open($tracker, $budget, $logger);

// ... リクエスト処理 ...

$summary = $scope->close();
echo $summary->queryCount;       // 実行されたクエリ数
echo $summary->totalTimeMs;      // 合計実行時間（ミリ秒）
echo $summary->hasViolations();  // 上限超過があれば true
```

### 3. PSR-15 ミドルウェア（リクエストごとにスコープを自動管理）

```php
use QueryCap\Middleware\QueryCapMiddleware;

$app->add(new QueryCapMiddleware(
    tracker: $tracker,
    budget:  $budget,
    logger:  $logger,   // PSR-3、省略可
));
```

### 4. PHPUnit アサーション

```php
use QueryCap\Testing\QueryCapAssertions;

final class UserServiceTest extends TestCase
{
    use QueryCapAssertions;

    public function test_list_runs_at_most_3_queries(): void
    {
        $this->assertMaxQueries(3, fn() => $this->service->list(), $this->tracker);
    }

    public function test_find_runs_exactly_1_query(): void
    {
        $this->assertExactQueries(1, fn() => $this->service->find(1), $this->tracker);
    }

    public function test_cached_result_runs_no_queries(): void
    {
        $this->assertNoQueries(fn() => $this->service->findCached(1), $this->tracker);
    }
}
```

## 違反アクション

| アクション | 動作 |
|---|---|
| `ViolationAction::Log` | PSR-3 で warning ログを出力（デフォルト） |
| `ViolationAction::Throw` | `BudgetExceededException` をスロー |
| `ViolationAction::Ignore` | 違反を記録するのみ（サイレント） |

## バジェットオプション

```php
QueryCap::create()
    ->maxQueries(50)                                  // クエリ数の上限
    ->maxTotalTime(Duration::milliseconds(200))        // 累計実行時間の上限
    ->maxSingleQueryTime(Duration::milliseconds(50))  // 単一クエリの実行時間上限
    ->warnAt(80)                                      // 各上限の 80% 到達で warning（1〜99）
    ->onViolation(ViolationAction::Log)
    ->build();
```

`warnAt` はハードリミットに達する前に PSR-3 warning を出します。たとえば `maxQueries(50)->warnAt(80)` なら 40 クエリ時点で警告が発火し、強制執行の前に調査する余裕が生まれます。

## クエリの内容を調べる

`QueryScope::close()` が返す `QuerySummary` から実際のクエリ一覧を取得できます。

```php
$summary = $scope->close();

// スコープ内で実行された全クエリ
foreach ($summary->queries() as $record) {
    echo $record->sql;         // SQL 文字列
    echo $record->durationMs;  // 実行時間（ミリ秒）
    echo $record->executedAt;  // DateTimeImmutable
}

// しきい値を超えたクエリのみ
$slow = $summary->slowQueries(Duration::milliseconds(50));
```

## PSR-14 イベント

PSR-14 互換のイベントディスパッチャーを通じてクエリイベントを購読できます。

```php
// クエリ実行ごとに発火
QueryCap\Event\QueryExecutedEvent::class

// バジェット上限を超えたときに発火
QueryCap\Event\BudgetViolatedEvent::class
```

## ネストしたスコープ

スコープはネスト可能です。各スコープはそのスコープがアクティブな間に実行されたクエリだけを追跡します。

```php
$outer = QueryScope::open($tracker, $budget);

    $inner = QueryScope::open($tracker, $budget);
    // ... いくつかのクエリ ...
    $innerSummary = $inner->close();  // 内側のクエリのみ集計

$outerSummary = $outer->close();      // 外側のクエリのみ集計
```

## Aegis との連携

QueryCap は [Aegis](https://github.com/kissmint3395/aegis) と組み合わせることで耐障害性を高められます。

```php
use Aegis\ResiliencePipeline;

$pipeline = ResiliencePipeline::builder()
    ->timeout(Duration::seconds(5))
    ->circuitBreaker('db', failureThreshold: 5)
    ->build();

// QueryCap がスロークエリを検知 → Aegis のサーキットブレーカーが DB 劣化時に開く
$scope = QueryScope::open($tracker, $budget);
$pipeline->execute(fn() => $db->query('SELECT ...'));
$scope->close();
```

## 動作要件

- PHP 8.2+
- `psr/log` ^3.0
- `psr/event-dispatcher` ^1.0
- `psr/http-server-middleware` ^1.0

## ライセンス

MIT
