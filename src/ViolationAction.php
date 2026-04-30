<?php

declare(strict_types=1);

namespace QueryCap;

enum ViolationAction
{
    case Log;
    case Throw;
    case Ignore;
}
