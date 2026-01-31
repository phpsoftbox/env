<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\Environment;
use PhpSoftBox\Env\EnvTypeEnum;
use PhpSoftBox\Env\Exception\ValidationException;
use PhpSoftBox\Env\Validator\RequiredValidator;
use PhpSoftBox\Env\Validator\TypeValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(RequiredValidator::class)]
#[CoversClass(TypeValidator::class)]
final class ValidatorTest extends TestCase
{
    /**
     * Проверяет, что RequiredValidator бросает исключение при отсутствии переменных.
     */
    #[Test]
    public function requiredValidatorThrowsWhenMissing(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "DB_HOST=localhost\n");

        try {
            $env = Environment::create($base)
                ->validate(new RequiredValidator(['DB_HOST', 'DB_NAME']));

            $this->expectException(ValidationException::class);
            $env->load();
        } finally {
            $this->cleanup($base);
        }
    }

    /**
     * Проверяет, что TypeValidator бросает исключение при неверном типе.
     */
    #[Test]
    public function typeValidatorThrowsOnInvalidType(): void
    {
        $base = $this->makeTempDir();
        file_put_contents($base . '/.env', "DB_PORT=abc\n");

        try {
            $env = Environment::create($base)
                ->validate(new TypeValidator(['DB_PORT' => EnvTypeEnum::INT]));

            $this->expectException(ValidationException::class);
            $env->load();
        } finally {
            $this->cleanup($base);
        }
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/psb_env_v_' . bin2hex(random_bytes(8));
        mkdir($base);

        return $base;
    }

    private function cleanup(string $base): void
    {
        $files = scandir($base);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                unlink($base . '/' . $file);
            }
        }

        rmdir($base);
    }
}
