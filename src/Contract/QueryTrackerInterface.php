<?php

declare(strict_types=1);

namespace QueryCap\Contract;

use QueryCap\QueryRecord;

interface QueryTrackerInterface
{
    public function record(QueryRecord $record): void;
}
