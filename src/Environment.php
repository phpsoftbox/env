<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use DateInterval;
use PhpSoftBox\Env\Parser\DotenvParser;
use PhpSoftBox\Env\Parser\ParserInterface;
use PhpSoftBox\Env\Reader\FileReader;
use PhpSoftBox\Env\Reader\ReaderInterface;
use PhpSoftBox\Env\Validator\ValidatorInterface;
use PhpSoftBox\Env\EnvironmentDetector;
use Psr\SimpleCache\CacheInterface;

use function is_array;

final class Environment
{
    public const string CACHE_KEY_PREFIX = 'config.envs';

    /**
     * @var list<string>
     */
    private array $paths;
    private ?string $environment = null;
    private bool $includeGlobals = true;
    private ?string $prefix = null;
    private ?CacheInterface $cache = null;
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
        $this->paths = $paths;
        $this->parser = new DotenvParser();
        $this->reader = new FileReader($this->paths, $this->parser);
    }

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

    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function setCache(?CacheInterface $cache, int|DateInterval|null $ttl = null): self
    {
        $this->cache = $cache;
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function setParser(ParserInterface $parser): self
    {
        $this->parser = $parser;
        $this->reader = new FileReader($this->paths, $this->parser);

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

    private function loadInternal(bool $overload, bool $strict): Variables
    {
        if ($this->cache !== null) {
            $cached = $this->cache->get(self::cacheKeyForEnvironment($this->environment));
            $variables = null;

            if ($cached instanceof Variables) {
                $variables = $cached;
            } elseif (is_array($cached)) {
                $variables = Variables::fromArray($cached, $this->prefix);
            }

            if ($variables !== null) {
                foreach ($this->validators as $validator) {
                    $validator->validate($variables);
                }

                EnvStorage::set($variables);

                return $variables;
            }
        }

        $variables = $this->reader->read(
            environment: $this->environment,
            includeGlobals: $this->includeGlobals,
            overload: $overload,
            prefix: $this->prefix,
            strict: $strict,
        );

        foreach ($this->validators as $validator) {
            $validator->validate($variables);
        }

        EnvStorage::set($variables);

        if ($this->cache !== null) {
            $this->cache->set(self::cacheKeyForEnvironment($this->environment), $variables, $this->cacheTtl);
        }

        return $variables;
    }

 
}
