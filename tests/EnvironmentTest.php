<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\Variables;
use PhpSoftBox\Cache\Driver\ArrayDriver;
use PhpSoftBox\Cache\Psr16\SimpleCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function implode;
use function is_array;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Environment::class)]
#[CoversClass(Variables::class)]
final class EnvironmentTest extends TestCase
{
    /**
     * Проверяет, что env-файлы загружаются и интерполяция работает.
     */
    #[Test]
    public function loadsEnvFilesAndInterpolates(): void
    {
        $base = $this->makeTempDir();

        file_put_contents($base . '/.env', implode("\n", [
            'APP_ROOT=/var/app',
            'LOG_DIR=${APP_ROOT}/logs',
            'HOST=localhost',
            'PLAIN=$HOST',
            'PORT=3306',
            'DEFAULT=${MISSING:-fallback}',
            'ALT=${HOST:+alt}',
            'SINGLE=\'single quoted\'',
            'DOUBLE="double quoted"',
            'INLINE=value # comment',
            'MULTI="line1\\nline2"',
            '',
        ]));

        try {
            $env = Environment::create($base)->setEnvironment('dev');
            $vars = $env->load();

            self::assertSame('/var/app', $vars->get('APP_ROOT'));
            self::assertSame('/var/app/logs', $vars->get('LOG_DIR'));
            self::assertSame('localhost', $vars->get('HOST'));
            self::assertSame('localhost', $vars->get('PLAIN'));
            self::assertSame('3306', $vars->get('PORT'));
            self::assertSame('fallback', $vars->get('DEFAULT'));
            self::assertSame('alt', $vars->get('ALT'));
            self::assertSame('single quoted', $vars->get('SINGLE'));
            self::assertSame('double quoted', $vars->get('DOUBLE'));
            self::assertSame('value', $vars->get('INLINE'));
            self::assertSame("line1\nline2", $vars->get('MULTI'));
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что overload предпочитает значения из файлов над глобальными.
     */
    #[Test]
    public function overloadPrefersFilesOverGlobals(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "SHARED=file\n");

        $snapshot = $this->snapshotGlobals(['SHARED']);
        $_ENV['SHARED'] = 'global';

        try {
            $env = Environment::create($base);

            self::assertSame('global', $env->load()->get('SHARED'));
            self::assertSame('file', $env->overload()->get('SHARED'));
        } finally {
            $this->restoreGlobals($snapshot);
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что env-специфичные файлы перекрывают базовые.
     */
    #[Test]
    public function mergesEnvironmentSpecificFiles(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "SHARED=base\n");
        file_put_contents($base . '/.env.dev', "SHARED=dev\n");

        try {
            $env = Environment::create($base)->setEnvironment('dev')->includeGlobals(false);
            $vars = $env->load();

            self::assertSame('dev', $vars->get('SHARED'));
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что safeLoad возвращает globals, если файлов нет.
     */
    #[Test]
    public function safeLoadReturnsGlobalsWhenFilesMissing(): void
    {
        $base = $this->makeTempDir();

        $snapshot = $this->snapshotGlobals(['ONLY_GLOBAL']);
        $_ENV['ONLY_GLOBAL'] = 'yes';

        try {
            $env = Environment::create($base);
            $vars = $env->safeLoad();

            self::assertSame('yes', $vars->get('ONLY_GLOBAL'));
        } finally {
            $this->restoreGlobals($snapshot);
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что префикс применяется к ключам в хранилище.
     */
    #[Test]
    public function respectsPrefixForStoredKeys(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "DB_HOST=localhost\n");

        try {
            $env = Environment::create($base)->setPrefix('APP_');
            $vars = $env->load();

            self::assertSame('localhost', $vars->get('DB_HOST'));
            self::assertSame('localhost', $vars->get('APP_DB_HOST'));
            self::assertArrayHasKey('APP_DB_HOST', $vars->all());
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что многострочные блоки парсятся корректно.
     */
    #[Test]
    public function parsesMultilineBlocks(): void
    {
        $base = $this->makeTempDir();

        file_put_contents($base . '/.env', implode("\n", [
            'KEY=-----BEGIN PRIVATE KEY-----',
            'line1',
            '-----END PRIVATE KEY-----',
            '',
        ]));

        try {
            $env = Environment::create($base);
            $vars = $env->load();

            self::assertSame("-----BEGIN PRIVATE KEY-----\nline1\n-----END PRIVATE KEY-----", $vars->get('KEY'));
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что local-переменные не экспортируются в globals.
     */
    #[Test]
    public function localVariablesAreNotExported(): void
    {
        $base = $this->makeTempDir();

        file_put_contents($base . '/.env', implode("\n", [
            'export EXPORTED=1',
            'local LOCAL_ONLY=2',
            '',
        ]));

        $snapshot = $this->snapshotGlobals(['EXPORTED', 'LOCAL_ONLY']);

        try {
            $env = Environment::create($base)->includeGlobals(false);
            $vars = $env->load();
            $vars->toGlobals();

            $envValues = $GLOBALS['_ENV'] ?? null;
            $serverValues = $GLOBALS['_SERVER'] ?? null;

            $exported = is_array($envValues) ? ($envValues['EXPORTED'] ?? null) : null;
            if ($exported === null && is_array($serverValues)) {
                $exported = $serverValues['EXPORTED'] ?? null;
            }

            $local = is_array($envValues) ? ($envValues['LOCAL_ONLY'] ?? null) : null;
            if ($local === null && is_array($serverValues)) {
                $local = $serverValues['LOCAL_ONLY'] ?? null;
            }

            self::assertSame('1', $exported);
            self::assertNull($local);
        } finally {
            $this->restoreGlobals($snapshot);
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что кеш получает env под ожидаемым ключом.
     */
    #[Test]
    public function cacheStoresLoadedVariables(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "APP_NAME=app\n");

        try {
            $cache = new SimpleCache(new ArrayDriver());

            $env = Environment::create($base)
                ->setEnvironment('dev')
                ->includeGlobals(false)
                ->setCache($cache);

            $env->load();

            $cached = $cache->get(Environment::cacheKeyForEnvironment('dev'));

            self::assertInstanceOf(Variables::class, $cached);
            self::assertSame('app', $cached->get('APP_NAME'));
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что при наличии кеша чтение идёт из него.
     */
    #[Test]
    public function cacheIsUsedForLoading(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "APP_NAME=file\n");

        try {
            $cache = new SimpleCache(new ArrayDriver());
            $cache->set(Environment::cacheKeyForEnvironment('dev'), Variables::fromArray(['APP_NAME' => 'cached']));

            $env = Environment::create($base)
                ->setEnvironment('dev')
                ->includeGlobals(false)
                ->setCache($cache);

            $vars = $env->load();

            self::assertSame('cached', $vars->get('APP_NAME'));
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, array{env: mixed, server: mixed}>
     */
    private function snapshotGlobals(array $keys): array
    {
        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array{env: mixed, server: mixed}> $snapshot
     */
    private function restoreGlobals(array $snapshot): void
    {
        foreach ($snapshot as $key => $values) {
            if ($values['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }

            if ($values['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }
        }
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/psb_env_' . bin2hex(random_bytes(8));
        mkdir($base);

        return $base;
    }

    private function cleanup(string $base): void
    {
        $files = scandir($base);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                unlink($base . '/' . $file);
            }
        }

        rmdir($base);
    }
}
