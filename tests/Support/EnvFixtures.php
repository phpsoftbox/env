<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Временные каталоги с env-файлами и снимки переменных процесса для тестов.
 */
trait EnvFixtures
{
    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    /**
     * @var array<string, array{env: mixed, server: mixed, getenv: string|false}>
     */
    private array $globalsSnapshot = [];

    protected function tearDown(): void
    {
        foreach ($this->globalsSnapshot as $key => $values) {
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

            putenv($values['getenv'] === false ? $key : $key . '=' . $values['getenv']);
        }

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->globalsSnapshot = [];
        $this->tempDirs        = [];

        parent::tearDown();
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/psb_env_' . bin2hex(random_bytes(8));
        mkdir($dir);

        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Создаёт файл (и недостающие каталоги) относительно $dir.
     */
    private function putFile(string $dir, string $relativePath, string $contents): string
    {
        $path = $dir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Запоминает переменные процесса и удаляет их из $_ENV, $_SERVER и getenv(); восстанавливаются в tearDown().
     */
    private function isolateGlobals(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (!isset($this->globalsSnapshot[$key])) {
                $this->globalsSnapshot[$key] = [
                    'env'    => $_ENV[$key] ?? null,
                    'server' => $_SERVER[$key] ?? null,
                    'getenv' => getenv($key),
                ];
            }

            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $info) {
            $info->isDir() ? rmdir($info->getPathname()) : unlink($info->getPathname());
        }

        rmdir($dir);
    }
}
