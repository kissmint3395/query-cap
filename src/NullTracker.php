<?php

declare(strict_types=1);

namespace QueryCap;

use QueryCap\Contract\QueryTrackerInterface;

final class NullTracker implements QueryTrackerInterface
{
    public function record(QueryRecord $record): void {}
}
