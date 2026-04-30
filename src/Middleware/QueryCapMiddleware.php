<?php

declare(strict_types=1);

namespace QueryCap\Middleware;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use QueryCap\QueryCap;
use QueryCap\QueryScope;
use QueryCap\QueryTracker;

final class QueryCapMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly QueryTracker $tracker,
        private readonly QueryCap $budget,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = QueryScope::open($this->tracker, $this->budget, $this->logger, $this->dispatcher);

        try {
            return $handler->handle($request);
        } finally {
            $scope->close();
        }
    }
}
