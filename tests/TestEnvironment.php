<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

enum TestEnvironment: string
{
    case DEV  = 'dev';
    case DEMO = 'demo';
}
