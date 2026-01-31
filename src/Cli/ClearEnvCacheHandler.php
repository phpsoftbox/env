<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Env\Environment;
use Psr\SimpleCache\CacheInterface;

use function is_string;

final readonly class ClearEnvCacheHandler implements HandlerInterface
{
    public function __construct(
        private ?CacheInterface $cache = null,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        if ($this->cache === null) {
            $runner->io()->writeln('Кеш не сконфигурирован (CacheInterface недоступен).', 'error');

            return Response::FAILURE;
        }

        $env = $runner->request()->option('environment');
        if ($env === '') {
            $env = null;
        }

        $key = Environment::cacheKeyForEnvironment(is_string($env) ? $env : null);

        if ($this->cache->delete($key)) {
            $runner->io()->writeln('Кеш env очищен (' . $key . ').', 'success');

            return Response::SUCCESS;
        }

        $runner->io()->writeln('Не удалось очистить кеш env (' . $key . ').', 'error');

        return Response::FAILURE;
    }
}
