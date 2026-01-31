<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Env\EnvValue;
use PhpSoftBox\Filter\LowercaseFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(EnvValue::class)]
final class EnvValueTest extends TestCase
{
    #[Test]
    public function exposesTypedValuesAndDefaults(): void
    {
        self::assertTrue(new EnvValue('yes', true)->bool());
        self::assertSame(8080, new EnvValue('8080', true)->int());
        self::assertSame(1.5, new EnvValue('1.5', true)->float());
        self::assertSame(['a', 'b'], new EnvValue('a, b', true)->array());
        self::assertSame('fallback', new EnvValue(null, false)->string('fallback'));
        self::assertFalse(new EnvValue(null, false)->exists());
    }

    #[Test]
    public function appliesFiltersAndResolvesBackedEnum(): void
    {
        $value = new EnvValue(' DEMO ', true)
            ->filtered(new LowercaseFilter());

        self::assertSame(TestEnvironment::DEMO, $value->enum(TestEnvironment::class));
    }

    #[Test]
    public function rejectsUnknownEnumValue(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new EnvValue('unknown', true)->enum(TestEnvironment::class);
    }

    #[Test]
    public function storageExposesTypedValueWithoutChangingRawGet(): void
    {
        $previous          = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';

        try {
            self::assertSame('false', EnvStorage::get('APP_DEBUG'));
            self::assertFalse(EnvStorage::value('APP_DEBUG')->bool());
            self::assertTrue(EnvStorage::value('APP_DEBUG')->exists());
            self::assertSame(8080, EnvStorage::value('MISSING_PORT', '8080')->int());
            self::assertFalse(EnvStorage::value('MISSING_PORT', '8080')->exists());
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $previous;
            }

            EnvStorage::clear();
        }
    }
}

enum TestEnvironment: string
{
    case DEV  = 'dev';
    case DEMO = 'demo';
}
