<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

/**
 * Хранилище значений для env(): содержит только значения из .env-файлов.
 *
 * Переменные процесса ($_ENV, $_SERVER, getenv()) не копируются в хранилище, а читаются при каждом обращении.
 * При $globalsFirst = true (режим load()/safeLoad() с globals) значение процесса перекрывает значение из файла,
 * иначе значения из файлов приоритетнее, а переменные процесса используются как запасной вариант.
 */
final class EnvStorage
{
    private static ?Variables $variables = null;
    private static bool $globalsFirst    = false;

    public static function set(Variables $variables, bool $globalsFirst = false): void
    {
        self::$variables    = $variables;
        self::$globalsFirst = $globalsFirst;
    }

    public static function clear(): void
    {
        self::$variables    = null;
        self::$globalsFirst = false;
    }

    public static function has(string $key): bool
    {
        if (self::$variables?->resolveKey($key) !== null) {
            return true;
        }

        return EnvGlobals::has($key);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $storedKey = self::$variables?->resolveKey($key);

        if ($storedKey !== null) {
            if (self::$globalsFirst && EnvGlobals::has($storedKey)) {
                return EnvGlobals::get($storedKey);
            }

            return self::$variables->get($storedKey);
        }

        return EnvGlobals::get($key, $default);
    }

    public static function value(string $key, mixed $default = null): EnvValue
    {
        $exists = self::has($key);

        return new EnvValue(
            value: self::get($key, $default),
            exists: $exists,
        );
    }
}
