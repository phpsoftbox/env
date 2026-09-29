<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Reader;

use FilesystemIterator;
use InvalidArgumentException;
use PhpSoftBox\Env\EnvGlobals;
use PhpSoftBox\Env\EnvironmentDetector;
use PhpSoftBox\Env\Exception\EnvException;
use PhpSoftBox\Env\Parser\ParserInterface;
use PhpSoftBox\Env\Variables;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_merge;
use function array_unique;
use function array_values;
use function basename;
use function in_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_string;
use function realpath;
use function rtrim;
use function sort;
use function str_starts_with;

/**
 * Читает .env-файлы из заданных путей.
 *
 * Для каталога читаются только `.env` и `.env.{env}` в нём самом; обход подкаталогов включается явно
 * ($recursive = true) и пропускает скрытые каталоги, vendor и node_modules.
 * Путь к файлу читается как есть.
 */
final class FileReader implements ReaderInterface
{
    /**
     * Каталоги, которые не обходятся в рекурсивном режиме (помимо скрытых).
     */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules'];

    /**
     * @var list<string>
     */
    private array $paths;

    /**
     * @param list<string> $paths
     */
    public function __construct(
        array $paths,
        private readonly ParserInterface $parser,
        private readonly bool $recursive = false,
    ) {
        $this->paths = $this->normalizePaths($paths);
    }

    public function files(?string $environment = null): array
    {
        return $this->resolveFiles($this->resolveEnvironment($environment, EnvGlobals::all()));
    }

    public function read(
        ?string $environment = null,
        ?string $prefix = null,
        bool $strict = true,
        bool $interpolateGlobals = true,
    ): Variables {
        $context     = $interpolateGlobals ? EnvGlobals::all() : [];
        $environment = $this->resolveEnvironment($environment, $context);
        $files       = $this->resolveFiles($environment);

        if ($files === [] && $strict) {
            throw new EnvException('No .env files found for provided paths.');
        }

        $fileValues = [];
        $exportable = [];

        foreach ($files as $path) {
            $parsed     = $this->parser->parse($path, $context);
            $fileValues = array_merge($fileValues, $parsed->values);
            $exportable = array_merge($exportable, $parsed->exportable);
            $context    = array_merge($context, $parsed->values);
        }

        return Variables::fromParsed($fileValues, $exportable, $prefix, true);
    }

    /**
     * Окружение: явно заданное, затем APP_ENV процесса, затем APP_ENV из базовых файлов (.env), иначе dev.
     *
     * @param array<string, string> $context
     */
    private function resolveEnvironment(?string $environment, array $context): string
    {
        if ($environment !== null && $environment !== '') {
            return $environment;
        }

        $detected = EnvironmentDetector::fromProcess();
        if ($detected !== null) {
            return $detected;
        }

        $values = [];
        foreach ($this->resolveBaseFiles() as $path) {
            $parsed  = $this->parser->parse($path, $context);
            $values  = array_merge($values, $parsed->values);
            $context = array_merge($context, $parsed->values);
        }

        $fromFiles = $values['APP_ENV'] ?? '';

        return $fromFiles !== '' ? $fromFiles : EnvironmentDetector::DEFAULT_ENVIRONMENT;
    }

    /**
     * Файлы, не зависящие от окружения: `.env` каталогов и явно указанные файлы.
     *
     * @return list<string>
     */
    private function resolveBaseFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                $files[] = $this->assertEnvFile($path);
                continue;
            }

            foreach ($this->directories($path) as $dir) {
                if (is_file($dir . '/.env')) {
                    $files[] = $this->assertEnvFile($dir . '/.env', $path);
                }
            }
        }

        return array_values(array_unique($files));
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

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function resolveDirectoryFiles(string $root, string $environment): array
    {
        $files = [];
        foreach ($this->directories($root) as $dir) {
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

    /**
     * Каталоги для поиска файлов: сам каталог, а в рекурсивном режиме — ещё и вложенные (корень первым).
     *
     * @return list<string>
     */
    private function directories(string $root): array
    {
        if (!$this->recursive) {
            return [$root];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $info): bool => $info->isDir()
                    && !str_starts_with($info->getFilename(), '.')
                    && !in_array($info->getFilename(), self::SKIPPED_DIRECTORIES, true),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $directories = [];
        foreach ($iterator as $info) {
            $directories[] = $info->getPathname();
        }

        sort($directories);

        return [$root, ...$directories];
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
