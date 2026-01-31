<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Cli;

use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class EnvCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'env:cache:clear',
            description: 'Очистить кеш env для выбранного окружения',
            signature: [],
            handler: ClearEnvCacheHandler::class,
        ));
    }
}
