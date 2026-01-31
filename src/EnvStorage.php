<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use function array_key_exists;
use function is_array;

final class EnvStorage
{
    private static ?Variables $variables = null;

    public static function set(Variables $variables): void
    {
        self::$variables = $variables;
    }

    public static function clear(): void
    {
        self::$variables = null;
    }

    public static function has(string $key): bool
    {
        if (self::$variables !== null && self::$variables->has($key)) {
            return true;
        }

        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env) && array_key_exists($key, $env)) {
            return true;
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server) && array_key_exists($key, $server)) {
            return true;
        }

        return false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$variables !== null && self::$variables->has($key)) {
            return self::$variables->get($key);
        }

        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env) && array_key_exists($key, $env)) {
            return $env[$key];
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server) && array_key_exists($key, $server)) {
            return $server[$key];
        }

        return $default;
    }
}
