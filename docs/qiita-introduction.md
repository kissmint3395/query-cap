# PHPでSQLクエリ数を予算管理する — query-cap のご紹介

## はじめに

PHPアプリを開発していて、こんな経験はありませんか？

- **新機能をデプロイしたら、1リクエストで200クエリが走っていた**（気づいたのは本番）
- **「このAPIは最大10クエリ以内」とテストで保証したい**が、どう書けばいいかわからない
- **N+1問題をコードレビューで毎回指摘している**が、なかなか根絶できない

今回紹介する **[query-cap](https://github.com/kissmint3395/query-cap)** はこれらをまとめて解決する PHP 8.2+ ライブラリです。

---

## query-cap とは

> SQLクエリに「予算（バジェット）」を設定し、超過を検知・強制するライブラリ

3つの場面で使えます。

| 場面 | できること |
|------|-----------|
| **開発中** | クエリ爆発をすぐ検知してログに出す |
| **テスト** | 「このメソッドは最大3クエリ」をアサートする |
| **本番** | 上限を超えたら例外を投げる or 警告だけ出す |

フレームワーク非依存（PDOベースならどこでも動く）、PHPStan level 8 対応の完全型付きライブラリです。

---

## インストール

```bash
composer require kissmint3395/query-cap
```

PHP 8.2+ が必要です。

---

## ステップ1：PDO接続をラップする

query-cap はPDOをラップする形で動作します。既存コードの変更は最小限です。

```php
use QueryCap\QueryTracker;
use QueryCap\Tracker\TrackingConnection;

// 既存のPDOをそのままラップするだけ
$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$tracker = new QueryTracker();
$db = new TrackingConnection($pdo, $tracker);

// あとは $db を普通のPDOとして使う（APIは完全互換）
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => 1]);
$users = $stmt->fetchAll();
```

`TrackingConnection` は通常の `PDO` と同じメソッドを持ちます。`prepare()` `query()` `exec()` などすべて使えます。

---

## ステップ2：スコープを開いてバジェットを設定する

「このリクエスト（or 処理）では最大50クエリ、合計200ms以内」というルールを定義します。

```php
use QueryCap\Duration;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\ViolationAction;

$budget = QueryCap::create()
    ->maxQueries(50)                                 // クエリ数の上限
    ->maxTotalTime(Duration::milliseconds(200))      // 累計実行時間の上限
    ->maxSingleQueryTime(Duration::milliseconds(50)) // 単一クエリの上限
    ->onViolation(ViolationAction::Log)              // 超過時はログに残す
    ->build();

// スコープを開く
$scope = QueryScope::open($tracker, $budget, $logger);

// ... ここでDBアクセスする処理 ...

// スコープを閉じて結果を受け取る
$summary = $scope->close();

echo $summary->queryCount;      // 実行されたクエリ数
echo $summary->totalTimeMs;     // 合計時間（ms）
echo $summary->hasViolations(); // 上限を超えたか
```

### 違反時の動作を選べる

```php
ViolationAction::Log    // PSR-3 でwarningログを出す（デフォルト）
ViolationAction::Throw  // BudgetExceededException を投げる
ViolationAction::Ignore // 記録するだけ（サイレント）
```

開発中は `Throw` にしておくと、クエリ爆発を即座に発見できます。

---

## ステップ3：テストでクエリ数をアサートする

`QueryCapAssertions` トレイトを使うと、テストにクエリ数の制約を書けます。

```php
use PHPUnit\Framework\TestCase;
use QueryCap\Testing\QueryCapAssertions;

final class UserServiceTest extends TestCase
{
    use QueryCapAssertions;

    private QueryTracker $tracker;
    private UserService $service;

    protected function setUp(): void
    {
        $this->tracker = new QueryTracker();
        $db = new TrackingConnection($pdo, $this->tracker);
        $this->service = new UserService($db);
    }

    // ユーザー一覧取得は最大3クエリ以内
    public function test_list_runs_at_most_3_queries(): void
    {
        $this->assertMaxQueries(3, fn() => $this->service->list(), $this->tracker);
    }

    // ユーザー取得はちょうど1クエリ
    public function test_find_runs_exactly_1_query(): void
    {
        $this->assertExactQueries(1, fn() => $this->service->find(1), $this->tracker);
    }

    // キャッシュ済みの結果はDBアクセスしない
    public function test_cached_result_runs_no_queries(): void
    {
        $this->assertNoQueries(fn() => $this->service->findCached(1), $this->tracker);
    }

    // 最大合計時間のアサーションも可能
    public function test_bulk_insert_completes_within_500ms(): void
    {
        $this->assertMaxTotalTime(
            Duration::milliseconds(500),
            fn() => $this->service->bulkInsert($data),
            $this->tracker
        );
    }
}
```

このテストが CI で通り続ける限り、「知らない間にN+1が増えていた」という事態を防げます。

---

## ステップ4：本番への適用（PSR-15ミドルウェア）

毎回 `open()`/`close()` を書くのは面倒です。PSR-15ミドルウェアを使えば、リクエストごとに自動でスコープを管理できます。

```php
use QueryCap\Middleware\QueryCapMiddleware;

// Slim / Mezzio / 任意のPSR-15対応フレームワークに追加
$app->add(new QueryCapMiddleware(
    tracker: $tracker,
    budget:  $budget,
    logger:  $logger,
));
```

ミドルウェアはリクエスト開始時にスコープを開き、レスポンス返却時（例外発生時も）に必ず閉じます。

---

## ステップ5：ソフトリミットで早期警告（warnAt）

ハードリミットに達する前に警告を出す **warnAt** 機能もあります。

```php
$budget = QueryCap::create()
    ->maxQueries(100)
    ->warnAt(80)                          // 80クエリ（= 80%）で事前警告
    ->onViolation(ViolationAction::Throw) // 100クエリ超えたら例外
    ->build();
```

本番では `warnAt(80)` で余裕を持って警告を受け取り、`onViolation(Throw)` で確実にブロックする、という二段構えにできます。

---

## ステップ6：実行されたSQLを詳しく調べる

`QuerySummary` からクエリの詳細も取得できます。デバッグや開発時のプロファイリングに便利です。

```php
$summary = $scope->close();

// 全クエリを確認
foreach ($summary->queries() as $record) {
    echo $record->sql;          // 実行されたSQL
    echo $record->durationMs;   // 実行時間（ms）
    echo $record->executedAt;   // 実行日時
}

// 50ms以上かかったクエリだけ絞り込む
$slowQueries = $summary->slowQueries(Duration::milliseconds(50));
foreach ($slowQueries as $record) {
    $logger->warning('Slow query detected', [
        'sql' => $record->sql,
        'duration_ms' => $record->durationMs,
    ]);
}
```

---

## PSR-14イベントで独自処理を追加する

クエリ実行・違反発生をフックして独自処理を追加することもできます。

```php
// 全クエリ実行時に発火
QueryCap\Event\QueryExecutedEvent::class

// バジェット上限超過時に発火
QueryCap\Event\BudgetViolatedEvent::class
```

PSR-14対応のイベントディスパッチャー（`league/event` や `symfony/event-dispatcher` など）に登録するだけです。

---

## ネストしたスコープ

スコープはネスト可能です。外側のスコープは全体を、内側のスコープは特定の処理だけを監視できます。

```php
$outer = QueryScope::open($tracker, $outerBudget);  // リクエスト全体
    // ... 何かの処理 ...

    $inner = QueryScope::open($tracker, $innerBudget);  // 重い処理だけ厳しく
    $heavyResult = $service->heavyOperation();
    $innerSummary = $inner->close();   // 内側だけ集計

$outerSummary = $outer->close();       // 外側だけ集計
```

---

## まとめ

| やりたいこと | 使う機能 |
|-------------|---------|
| クエリ数・時間を制限したい | `QueryCap::create()->maxQueries()->build()` |
| 違反をログに出したい | `ViolationAction::Log` |
| テストでクエリ数を保証したい | `QueryCapAssertions` トレイト |
| リクエスト単位で自動管理したい | `QueryCapMiddleware` |
| 上限前に早期警告したい | `->warnAt(80)` |
| 遅いクエリを特定したい | `$summary->slowQueries(Duration::milliseconds(50))` |

**query-cap** を使うことで「クエリ爆発は本番で気づく」問題から解放されます。テストに `assertMaxQueries` を1行加えるだけでも、N+1の侵入を防ぐ安全網になります。

ぜひ試してみてください。

https://github.com/kissmint3395/query-cap
