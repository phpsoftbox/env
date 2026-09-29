<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use function array_key_exists;
use function getenv;
use function is_array;
use function is_scalar;
use function is_string;

/**
 * Живое чтение переменных процесса: $_ENV, $_SERVER и getenv().
 *
 * Значения никогда не кешируются: при каждом обращении читается текущее состояние глобальных массивов,
 * поэтому данные запроса (HTTP_*) не переживают запрос, а изменения окружения процесса видны сразу.
 */
final class EnvGlobals
{
    /**
     * Снимок скалярных значений из $_ENV и $_SERVER ($_ENV приоритетнее).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $data = [];

        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env)) {
            foreach ($env as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $data[$key] = (string) $value;
                }
            }
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server)) {
            foreach ($server as $key => $value) {
                if (is_string($key) && is_scalar($value) && !array_key_exists($key, $data)) {
                    $data[$key] = (string) $value;
                }
            }
        }

        return $data;
    }

    public static function has(string $key): bool
    {
        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env) && array_key_exists($key, $env)) {
            return true;
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server) && array_key_exists($key, $server)) {
            return true;
        }

        return $key !== '' && getenv($key) !== false;
    }

    /**
     * Возвращает исходное значение из $_ENV, затем $_SERVER, затем getenv().
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env) && array_key_exists($key, $env)) {
            return $env[$key];
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server) && array_key_exists($key, $server)) {
            return $server[$key];
        }

        if ($key !== '') {
            $value = getenv($key);
            if ($value !== false) {
                return $value;
            }
        }

        return $default;
    }
}
