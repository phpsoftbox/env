<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use function getenv;
use function is_array;

final class EnvironmentDetector
{
    public const string DEFAULT_ENVIRONMENT = 'dev';

    /**
     * Окружение из переменных процесса или значение по умолчанию (dev).
     */
    public static function detect(string $name = 'APP_ENV'): string
    {
        return self::fromProcess($name) ?? self::DEFAULT_ENVIRONMENT;
    }

    /**
     * Окружение из $_ENV, $_SERVER или getenv(); null, если переменная не задана или пуста.
     */
    public static function fromProcess(string $name = 'APP_ENV'): ?string
    {
        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env) && isset($env[$name]) && $env[$name] !== '') {
            return (string) $env[$name];
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server) && isset($server[$name]) && $server[$name] !== '') {
            return (string) $server[$name];
        }

        $envVar = getenv($name);
        if ($envVar !== false && $envVar !== '') {
            return $envVar;
        }

        return null;
    }
}
