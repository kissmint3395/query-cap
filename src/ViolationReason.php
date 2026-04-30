<?php

declare(strict_types=1);

namespace QueryCap;

enum ViolationReason
{
    case MaxQueriesExceeded;
    case MaxTotalTimeExceeded;
    case MaxSingleQueryTimeExceeded;
}
