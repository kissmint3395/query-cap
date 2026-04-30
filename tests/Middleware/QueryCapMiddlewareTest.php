<?php

declare(strict_types=1);

namespace QueryCap\Tests\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use QueryCap\Exception\BudgetExceededException;
use QueryCap\Middleware\QueryCapMiddleware;
use QueryCap\QueryCap;
use QueryCap\QueryRecord;
use QueryCap\QueryTracker;
use QueryCap\ViolationAction;

final class QueryCapMiddlewareTest extends TestCase
{
    private function makeHandler(callable $fn): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->fn)();

                return new Response(200);
            }
        };
    }

    public function test_opens_and_closes_scope_per_request(): void
    {
        $tracker = new QueryTracker();
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $middleware = new QueryCapMiddleware($tracker, $budget);

        $queriesRecorded = 0;
        $handler = $this->makeHandler(function () use ($tracker, &$queriesRecorded): void {
            $tracker->record(new QueryRecord('SELECT 1', 1.0, new \DateTimeImmutable()));
            $tracker->record(new QueryRecord('SELECT 2', 1.0, new \DateTimeImmutable()));
            $queriesRecorded = 2;
        });

        $request = new ServerRequest('GET', '/');
        $middleware->process($request, $handler);

        $this->assertSame(2, $queriesRecorded);
        $this->assertFalse($tracker->hasActiveScope());
    }

    public function test_scope_is_closed_even_on_exception(): void
    {
        $tracker = new QueryTracker();
        $budget = QueryCap::create()->onViolation(ViolationAction::Ignore)->build();
        $middleware = new QueryCapMiddleware($tracker, $budget);

        $handler = $this->makeHandler(function (): void {
            throw new \RuntimeException('handler error');
        });

        $request = new ServerRequest('GET', '/');

        try {
            $middleware->process($request, $handler);
        } catch (\RuntimeException) {
        }

        $this->assertFalse($tracker->hasActiveScope());
    }

    public function test_violation_throw_propagates_from_handler(): void
    {
        $tracker = new QueryTracker();
        $budget = QueryCap::create()
            ->maxQueries(1)
            ->onViolation(ViolationAction::Throw)
            ->build();
        $middleware = new QueryCapMiddleware($tracker, $budget);

        $handler = $this->makeHandler(function () use ($tracker): void {
            $tracker->record(new QueryRecord('SELECT 1', 1.0, new \DateTimeImmutable()));
            $tracker->record(new QueryRecord('SELECT 2', 1.0, new \DateTimeImmutable()));
        });

        $request = new ServerRequest('GET', '/');

        $this->expectException(BudgetExceededException::class);
        $middleware->process($request, $handler);
    }
}
