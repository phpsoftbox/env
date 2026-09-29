<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Cache\Driver\ArrayDriver;
use PhpSoftBox\Cache\Psr16\SimpleCache;
use PhpSoftBox\CliApp\CliApp;
use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PhpSoftBox\CliApp\Io\NullIo;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\Env\Cli\ClearEnvCacheHandler;
use PhpSoftBox\Env\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClearEnvCacheHandler::class)]
#[CoversMethod(ClearEnvCacheHandler::class, 'run')]
final class ClearEnvCacheHandlerTest extends TestCase
{
    /**
     * Проверим, что без `--environment` очищается кеш окружения приложения (`prod`), а не `production`.
     *
     * @see ClearEnvCacheHandler::run()
     */
    #[Test]
    public function clearsCacheOfApplicationEnvironment(): void
    {
        $cache = new SimpleCache(new ArrayDriver());

        $cache->set(Environment::cacheKeyForEnvironment('prod'), ['APP_KEY' => 'x']);

        $registry = new InMemoryCommandRegistry(withDefaultCommands: false);

        $registry->register(Command::define('env:cache:clear', 'Clear env cache', [], new ClearEnvCacheHandler($cache)));
        $app = new CliApp($registry, new NullIo(), environmentResolver: static fn (): string => 'prod');

        self::assertSame(Response::SUCCESS, $app->runCommand('env:cache:clear', [])->code);
        self::assertFalse($cache->has(Environment::cacheKeyForEnvironment('prod')));
    }
}
