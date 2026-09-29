<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use DateInterval;
use PhpSoftBox\Env\Parser\DotenvParser;
use PhpSoftBox\Env\Parser\ParserInterface;
use PhpSoftBox\Env\Reader\FileReader;
use PhpSoftBox\Env\Reader\ReaderInterface;
use PhpSoftBox\Env\Validator\ValidatorInterface;
use Psr\SimpleCache\CacheInterface;

use function is_array;

final class Environment
{
    public const string CACHE_KEY_PREFIX = 'config.envs';

    /**
     * @var list<string>
     */
    private array $paths;
    private ?string $environment            = null;
    private bool $includeGlobals            = true;
    private ?string $prefix                 = null;
    private bool $recursive                 = false;
    private ?CacheInterface $cache          = null;
    private int|DateInterval|null $cacheTtl = null;
    private ParserInterface $parser;
    private ReaderInterface $reader;

    /**
     * @var list<ValidatorInterface>
     */
    private array $validators = [];

    /**
     * @param list<string> $paths
     */
    private function __construct(array $paths)
    {
        $this->paths  = $paths;
        $this->parser = new DotenvParser();

        $this->reader = new FileReader($this->paths, $this->parser);
    }

    /**
     * Загрузка из каталога: читаются только `.env` и `.env.{env}` этого каталога (без обхода подкаталогов).
     */
    public static function create(string $directory): self
    {
        return new self([$directory]);
    }

    /**
     * @param list<string> $paths
     */
    public static function createFromPaths(array $paths): self
    {
        return new self($paths);
    }

    public static function createFromFile(string $filepath): self
    {
        return new self([$filepath]);
    }

    public function setEnvironment(?string $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    public function includeGlobals(bool $includeGlobals): self
    {
        $this->includeGlobals = $includeGlobals;

        return $this;
    }

    /**
     * Префикс добавляется только к ключам из env-файлов (DB_HOST → APP_DB_HOST); переменные процесса
     * (PATH, HOME, ...) остаются без префикса. При поиске ключ проверяется с префиксом, затем как есть.
     */
    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function setCache(?CacheInterface $cache, int|DateInterval|null $ttl = null): self
    {
        $this->cache    = $cache;
        $this->cacheTtl = $ttl;

        return $this;
    }

    /**
     * Включает обход подкаталогов (кроме скрытых, vendor и node_modules) в поисках `.env`/`.env.{env}`.
     * Файлы вложенных каталогов перекрывают файлы корня. Сбрасывает ридер, заданный через setReader().
     */
    public function setRecursive(bool $recursive): self
    {
        $this->recursive = $recursive;
        $this->reader    = new FileReader($this->paths, $this->parser, $this->recursive);

        return $this;
    }

    public function setParser(ParserInterface $parser): self
    {
        $this->parser = $parser;
        $this->reader = new FileReader($this->paths, $this->parser, $this->recursive);

        return $this;
    }

    public function setReader(ReaderInterface $reader): self
    {
        $this->reader = $reader;

        return $this;
    }

    public function validate(ValidatorInterface $validator): self
    {
        $this->validators[] = $validator;

        return $this;
    }

    public function load(): Variables
    {
        return $this->loadInternal(overload: false, strict: true);
    }

    public function safeLoad(): Variables
    {
        return $this->loadInternal(overload: false, strict: false);
    }

    public function overload(): Variables
    {
        return $this->loadInternal(overload: true, strict: true);
    }

    public function getParser(): ParserInterface
    {
        return $this->parser;
    }

    public function getReader(): ReaderInterface
    {
        return $this->reader;
    }

    public static function cacheKeyForEnvironment(?string $environment = null): string
    {
        $env = $environment ?? EnvironmentDetector::detect();

        return self::CACHE_KEY_PREFIX . '.' . $env;
    }

    /**
     * В кеш попадают только значения из файлов; переменные процесса ($_ENV/$_SERVER) читаются заново
     * при каждой загрузке и в хранилище env() не копируются.
     */
    private function loadInternal(bool $overload, bool $strict): Variables
    {
        $fileVariables = $this->loadFileVariables($strict);

        $variables = $this->includeGlobals
            ? $fileVariables->withGlobals(EnvGlobals::all(), $overload)
            : $fileVariables;

        foreach ($this->validators as $validator) {
            $validator->validate($variables);
        }

        EnvStorage::set($fileVariables, globalsFirst: $this->includeGlobals && !$overload);

        return $variables;
    }

    private function loadFileVariables(bool $strict): Variables
    {
        if ($this->cache === null) {
            return $this->readFileVariables($strict);
        }

        $key    = self::cacheKeyForEnvironment($this->environment);
        $cached = $this->cache->get($key);

        if ($cached instanceof Variables) {
            return $cached;
        }

        if (is_array($cached)) {
            return Variables::fromArray($cached, $this->prefix);
        }

        $variables = $this->readFileVariables($strict);
        $this->cache->set($key, $variables, $this->cacheTtl);

        return $variables;
    }

    private function readFileVariables(bool $strict): Variables
    {
        return $this->reader->read(
            environment: $this->environment,
            prefix: $this->prefix,
            strict: $strict,
            interpolateGlobals: $this->includeGlobals,
        );
    }
}
