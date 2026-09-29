<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Cache\Driver\ArrayDriver;
use PhpSoftBox\Cache\Psr16\SimpleCache;
use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Env\Tests\Support\EnvFixtures;
use PhpSoftBox\Env\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function env;

#[CoversClass(Environment::class)]
#[CoversClass(Variables::class)]
#[CoversMethod(Environment::class, 'load')]
#[CoversMethod(Environment::class, 'setPrefix')]
#[CoversMethod(Variables::class, 'withGlobals')]
final class EnvironmentGlobalsTest extends TestCase
{
    use EnvFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        EnvStorage::clear();
    }

    /**
     * Проверим, что в кеш попадают только значения из файлов, без данных запроса из $_SERVER.
     *
     * @see Environment::load()
     */
    #[Test]
    public function cacheStoresOnlyFileValues(): void
    {
        $this->isolateGlobals('HTTP_COOKIE');
        $_SERVER['HTTP_COOKIE'] = 'session=secret';

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "APP_NAME=app\n");

        $cache = new SimpleCache(new ArrayDriver());

        Environment::create($dir)->setEnvironment('dev')->setCache($cache)->load();

        $cached = $cache->get(Environment::cacheKeyForEnvironment('dev'));

        self::assertInstanceOf(Variables::class, $cached);
        self::assertSame(['APP_NAME' => 'app'], $cached->all());
    }

    /**
     * Проверим, что при загрузке из кеша globals читаются заново: данные прогревающего запроса не видны следующему.
     *
     * @see Environment::load()
     */
    #[Test]
    public function globalsAreReadLiveAfterCacheHit(): void
    {
        $this->isolateGlobals('HTTP_AUTHORIZATION');

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "APP_NAME=app\n");

        $cache = new SimpleCache(new ArrayDriver());

        // Первый запрос прогревает кеш со своим заголовком.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer first';
        Environment::create($dir)->setEnvironment('dev')->setCache($cache)->load();

        // Второй запрос приходит без заголовка и читает env из кеша.
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $variables = Environment::create($dir)->setEnvironment('dev')->setCache($cache)->load();

        self::assertFalse($variables->has('HTTP_AUTHORIZATION'));
        self::assertNull(env('HTTP_AUTHORIZATION'));
    }

    /**
     * Проверим, что префикс применяется к ключам из файлов, но не к переменным процесса.
     *
     * @see Environment::setPrefix()
     * @see Variables::withGlobals()
     */
    #[Test]
    public function prefixIsNotAppliedToGlobals(): void
    {
        $this->isolateGlobals('PSB_GLOBAL_PATH', 'APP_PSB_GLOBAL_PATH');
        $_SERVER['PSB_GLOBAL_PATH'] = '/usr/bin';

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "DB_HOST=localhost\n");

        $variables = Environment::create($dir)->setPrefix('APP_')->load();

        self::assertSame('localhost', $variables->get('DB_HOST'));
        self::assertArrayHasKey('APP_DB_HOST', $variables->all());
        self::assertArrayHasKey('PSB_GLOBAL_PATH', $variables->all());
        self::assertArrayNotHasKey('APP_PSB_GLOBAL_PATH', $variables->all());
        self::assertSame('/usr/bin', $variables->get('PSB_GLOBAL_PATH'));
    }
}
