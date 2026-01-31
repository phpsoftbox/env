<?php

declare(strict_types=1);

use PhpSoftBox\Env\EnvStorage;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return EnvStorage::get($key, $default);
    }
}
