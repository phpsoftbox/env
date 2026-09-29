<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Env\Tests\Support\EnvFixtures;
use PhpSoftBox\Env\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;

#[CoversClass(EnvStorage::class)]
#[CoversMethod(EnvStorage::class, 'get')]
#[CoversMethod(EnvStorage::class, 'set')]
final class EnvStorageTest extends TestCase
{
    use EnvFixtures;

    protected function tearDown(): void
    {
        EnvStorage::clear();

        parent::tearDown();
    }

    /**
     * Проверим, что в режиме globalsFirst изменение переменной процесса видно сразу и перекрывает файл.
     *
     * @see EnvStorage::set()
     * @see EnvStorage::get()
     */
    #[Test]
    public function globalsFirstReadsProcessValueLive(): void
    {
        $this->isolateGlobals('PSB_STORAGE_KEY');
        EnvStorage::set(Variables::fromArray(['PSB_STORAGE_KEY' => 'file']), globalsFirst: true);

        self::assertSame('file', EnvStorage::get('PSB_STORAGE_KEY'));

        $_SERVER['PSB_STORAGE_KEY'] = 'process';

        self::assertSame('process', EnvStorage::get('PSB_STORAGE_KEY'));
    }

    /**
     * Проверим, что без globalsFirst значение из файла приоритетнее переменной процесса.
     *
     * @see EnvStorage::get()
     */
    #[Test]
    public function fileValueWinsWithoutGlobalsFirst(): void
    {
        $this->isolateGlobals('PSB_STORAGE_KEY');
        $_SERVER['PSB_STORAGE_KEY'] = 'process';

        EnvStorage::set(Variables::fromArray(['PSB_STORAGE_KEY' => 'file']));

        self::assertSame('file', EnvStorage::get('PSB_STORAGE_KEY'));
    }

    /**
     * Проверим, что отсутствующий в хранилище ключ читается из окружения процесса (getenv()).
     *
     * @see EnvStorage::get()
     */
    #[Test]
    public function fallsBackToProcessEnvironment(): void
    {
        $this->isolateGlobals('PSB_STORAGE_PUTENV');
        putenv('PSB_STORAGE_PUTENV=from-putenv');

        EnvStorage::set(Variables::fromArray([]));

        self::assertSame('from-putenv', EnvStorage::get('PSB_STORAGE_PUTENV'));
    }
}
