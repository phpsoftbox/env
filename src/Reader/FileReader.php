<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Reader;

use PhpSoftBox\Env\EnvironmentDetector;

use FilesystemIterator;
use InvalidArgumentException;
use PhpSoftBox\Env\Exception\EnvException;
use PhpSoftBox\Env\Parser\ParserInterface;
use PhpSoftBox\Env\Variables;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function basename;
use function is_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_scalar;
use function is_string;
use function realpath;
use function rtrim;
use function sort;
use function str_starts_with;

final class FileReader implements ReaderInterface
{
    /**
     * @var list<string>
     */
    private array $paths;

    public function __construct(
        array $paths,
        private readonly ParserInterface $parser,
    ) {
        $this->paths = $this->normalizePaths($paths);
    }

    public function files(?string $environment = null): array
    {
        $environment = $environment ?? EnvironmentDetector::detect();

        return $this->resolveFiles($environment);
    }

    public function read(
        ?string $environment = null,
        bool $includeGlobals = true,
        bool $overload = false,
        ?string $prefix = null,
        bool $strict = true,
    ): Variables {
        $environment = $environment ?? EnvironmentDetector::detect();
        $files = $this->resolveFiles($environment);

        if ($files === [] && $strict) {
            throw new EnvException('No .env files found for provided paths.');
        }

        $globals = $includeGlobals ? $this->readGlobals() : [];
        $context = $globals;

        $fileValues = [];
        $exportable = [];

        foreach ($files as $path) {
            $parsed = $this->parser->parse($path, $context);
            $fileValues = array_merge($fileValues, $parsed->values);
            $exportable = array_merge($exportable, $parsed->exportable);
            $context = array_merge($context, $parsed->values);
        }

        if ($includeGlobals) {
            if ($overload) {
                $fileValues = array_merge($globals, $fileValues);
            } else {
                $fileValues = array_merge($fileValues, $globals);
            }
        }

        return Variables::fromParsed($fileValues, $exportable, $prefix, true);
    }

    /**
     * @return array<string, string>
     */
    private function readGlobals(): array
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

    /**
     * @return list<string>
     */
    private function resolveFiles(string $environment): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            if (is_dir($path)) {
                $files = array_merge($files, $this->resolveDirectoryFiles($path, $environment));
                continue;
            }

            $files[] = $this->assertEnvFile($path);
        }

        $files = array_values(array_unique($files));

        return $files;
    }

    /**
     * @return list<string>
     */
    private function resolveDirectoryFiles(string $root, string $environment): array
    {
        $directories = [$root];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $info) {
            if ($info->isDir()) {
                $directories[] = $info->getPathname();
            }
        }

        $directories = array_values(array_unique($directories));
        sort($directories);

        $files = [];
        foreach ($directories as $dir) {
            $base = $dir . '/.env';
            if (is_file($base)) {
                $files[] = $this->assertEnvFile($base, $root);
            }

            $envFile = $dir . '/.env.' . $environment;
            if (is_file($envFile)) {
                $files[] = $this->assertEnvFile($envFile, $root);
            }
        }

        return $files;
    }

    private function assertEnvFile(string $path, ?string $root = null): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new InvalidArgumentException('Env file does not exist: ' . $path);
        }

        if (!str_starts_with(basename($real), '.env')) {
            throw new InvalidArgumentException('Env file must start with .env: ' . $path);
        }

        if ($root !== null) {
            $rootReal = realpath($root);
            if ($rootReal === false || !str_starts_with($real, $rootReal)) {
                throw new InvalidArgumentException('Env file is outside of allowed root: ' . $path);
            }
        }

        if (!is_readable($real)) {
            throw new InvalidArgumentException('Env file is not readable: ' . $path);
        }

        return $real;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        if ($paths === []) {
            throw new InvalidArgumentException('At least one path is required.');
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $real = realpath($path);
            if ($real === false) {
                throw new InvalidArgumentException('Path does not exist: ' . $path);
            }

            if (!is_dir($real) && !is_file($real)) {
                throw new InvalidArgumentException('Path must be a file or directory: ' . $path);
            }

            $normalized[] = rtrim($real, '/');
        }

        return array_values(array_unique($normalized));
    }
}
