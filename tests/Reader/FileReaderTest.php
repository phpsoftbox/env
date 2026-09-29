<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests\Reader;

use PhpSoftBox\Env\Parser\DotenvParser;
use PhpSoftBox\Env\Reader\FileReader;
use PhpSoftBox\Env\Tests\Support\EnvFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;
use function realpath;

#[CoversClass(FileReader::class)]
#[CoversMethod(FileReader::class, 'read')]
#[CoversMethod(FileReader::class, 'files')]
final class FileReaderTest extends TestCase
{
    use EnvFixtures;

    /**
     * Проверим, что по умолчанию читается только указанный каталог, а `.env` вложенных каталогов игнорируются.
     *
     * @see FileReader::files()
     */
    #[Test]
    public function readsOnlyConfiguredDirectoryByDefault(): void
    {
        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "SHARED=root\n");
        $this->putFile($dir, 'nested/.env', "SHARED=nested\n");

        $reader = new FileReader([$dir], new DotenvParser());

        self::assertSame([realpath($dir . '/.env')], $reader->files('dev'));
    }

    /**
     * Проверим, что в рекурсивном режиме обходятся подкаталоги, кроме vendor, node_modules и скрытых.
     *
     * @see FileReader::files()
     */
    #[Test]
    public function recursiveModeSkipsVendorNodeModulesAndHiddenDirectories(): void
    {
        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "A=1\n");
        $this->putFile($dir, 'module/.env', "A=2\n");
        $this->putFile($dir, 'vendor/pkg/.env', "A=3\n");
        $this->putFile($dir, 'node_modules/pkg/.env', "A=4\n");
        $this->putFile($dir, '.git/.env', "A=5\n");

        $reader = new FileReader([$dir], new DotenvParser(), recursive: true);

        self::assertSame(
            [realpath($dir . '/.env'), realpath($dir . '/module/.env')],
            $reader->files('dev'),
        );
    }

    /**
     * Проверим, что APP_ENV, заданный только в `.env`, выбирает файл `.env.{env}`, который перекрывает `.env`.
     *
     * @see FileReader::read()
     */
    #[Test]
    public function selectsEnvironmentFileByAppEnvFromBaseFile(): void
    {
        $this->isolateGlobals('APP_ENV');

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "APP_ENV=prod\nSHARED=base\n");
        $this->putFile($dir, '.env.prod', "SHARED=prod\n");
        $this->putFile($dir, '.env.dev', "SHARED=dev\n");

        $variables = new FileReader([$dir], new DotenvParser())->read();

        self::assertSame('prod', $variables->get('SHARED'));
    }

    /**
     * Проверим, что APP_ENV процесса приоритетнее значения из `.env` при выборе файла окружения.
     *
     * @see FileReader::read()
     */
    #[Test]
    public function processAppEnvOverridesBaseFile(): void
    {
        $this->isolateGlobals('APP_ENV');
        putenv('APP_ENV=test');

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "APP_ENV=prod\nSHARED=base\n");
        $this->putFile($dir, '.env.prod', "SHARED=prod\n");
        $this->putFile($dir, '.env.test', "SHARED=test\n");

        $variables = new FileReader([$dir], new DotenvParser())->read();

        self::assertSame('test', $variables->get('SHARED'));
    }

    /**
     * Проверим, что результат чтения содержит только значения из файлов, а globals используются лишь для интерполяции.
     *
     * @see FileReader::read()
     */
    #[Test]
    public function readReturnsOnlyFileValues(): void
    {
        $this->isolateGlobals('PSB_READER_GLOBAL');
        $_SERVER['PSB_READER_GLOBAL'] = 'global';

        $dir = $this->makeTempDir();
        $this->putFile($dir, '.env', "FROM_FILE=\${PSB_READER_GLOBAL}-file\n");

        $variables = new FileReader([$dir], new DotenvParser())->read('dev');

        self::assertSame(['FROM_FILE' => 'global-file'], $variables->all());
    }
}
