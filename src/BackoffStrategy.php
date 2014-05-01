<?php

declare(strict_types=1);

namespace PhilipRehberger\Retry;

enum BackoffStrategy
{
    case Exponential;
    case Linear;
    case Constant;
}
