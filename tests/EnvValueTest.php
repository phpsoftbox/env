<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Env\EnvValue;
use PhpSoftBox\Filter\LowercaseFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(EnvValue::class)]
#[CoversMethod(EnvValue::class, 'array')]
#[CoversMethod(EnvValue::class, 'enum')]
final class EnvValueTest extends TestCase
{
    /**
     * Проверим типизированные преобразования и значения по умолчанию.
     *
     * @see EnvValue::bool()
     * @see EnvValue::int()
     * @see EnvValue::float()
     * @see EnvValue::array()
     * @see EnvValue::string()
     */
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

    /**
     * Проверим, что фильтры применяются до преобразования в backed enum.
     *
     * @see EnvValue::filtered()
     * @see EnvValue::enum()
     */
    #[Test]
    public function appliesFiltersAndResolvesBackedEnum(): void
    {
        $value = new EnvValue(' DEMO ', true)
            ->filtered(new LowercaseFilter());

        self::assertSame(TestEnvironment::DEMO, $value->enum(TestEnvironment::class));
    }

    /**
     * Проверим, что неизвестное значение enum приводит к исключению.
     *
     * @see EnvValue::enum()
     */
    #[Test]
    public function rejectsUnknownEnumValue(): void
    {
        $this->expectException(UnexpectedValueException::class);

        new EnvValue('unknown', true)->enum(TestEnvironment::class);
    }

    /**
     * Проверим, что EnvStorage::value() даёт типизированный доступ, а get() возвращает исходное значение.
     *
     * @see EnvStorage::value()
     * @see EnvStorage::get()
     */
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

    /**
     * Проверим, что одиночное значение без запятых превращается в список из одного элемента.
     *
     * @see EnvValue::array()
     */
    #[Test]
    public function arrayAcceptsSingleElement(): void
    {
        self::assertSame(['admin'], new EnvValue(' admin ', true)->array());
    }

    /**
     * Проверим, что список в квадратных скобках без кавычек (`[a, b]`) разбирается без скобок в элементах.
     *
     * @see EnvValue::array()
     */
    #[Test]
    public function arrayParsesBracketedListWithoutQuotes(): void
    {
        self::assertSame(['a', 'b'], new EnvValue('[a, b]', true)->array());
    }

    /**
     * Проверим, что некорректный JSON-объект не разбивается по запятым, а возвращает значение по умолчанию.
     *
     * @see EnvValue::array()
     */
    #[Test]
    public function arrayReturnsDefaultForInvalidJsonObject(): void
    {
        self::assertSame(['default'], new EnvValue('{a: 1, b: 2}', true)->array(['default']));
    }
}
