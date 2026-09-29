<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Env\Environment;
use Psr\SimpleCache\CacheInterface;

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

        // Окружение приложения (или --environment): тот же ключ, под которым Environment сохранил кеш.
        $key = Environment::cacheKeyForEnvironment($runner->environment());

        if ($this->cache->delete($key)) {
            $runner->io()->writeln('Кеш env очищен (' . $key . ').', 'success');

            return Response::SUCCESS;
        }

        $runner->io()->writeln('Не удалось очистить кеш env (' . $key . ').', 'error');

        return Response::FAILURE;
    }
}
