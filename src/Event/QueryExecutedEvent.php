<?php

declare(strict_types=1);

namespace QueryCap\Event;

use QueryCap\QueryRecord;

final readonly class QueryExecutedEvent
{
    public function __construct(
        public QueryRecord $record,
    ) {}
}
