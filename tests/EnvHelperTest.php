<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\EnvStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(EnvStorage::class)]
final class EnvHelperTest extends TestCase
{
    /**
     * Проверяет, что env() читает из хранилища перед globals.
     */
    #[Test]
    public function envHelperReadsFromStorageBeforeGlobals(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "APP_NAME=local\n");

        $snapshot = $this->snapshotGlobals(['APP_NAME']);
        $_ENV['APP_NAME'] = 'global';

        try {
            $env = Environment::create($base)->includeGlobals(false);
            $env->load();

            self::assertSame('local', \env('APP_NAME'));
        } finally {
            $this->restoreGlobals($snapshot);
            EnvStorage::clear();
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что env() берёт значение из globals, если хранилище пустое.
     */
    #[Test]
    public function envHelperFallsBackToGlobals(): void
    {
        EnvStorage::clear();

        $snapshot = $this->snapshotGlobals(['ONLY_GLOBAL']);
        $_ENV['ONLY_GLOBAL'] = 'yes';

        try {
            self::assertSame('yes', \env('ONLY_GLOBAL'));
        } finally {
            $this->restoreGlobals($snapshot);
            EnvStorage::clear();
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
        $base = sys_get_temp_dir() . '/psb_env_helper_' . bin2hex(random_bytes(8));
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
