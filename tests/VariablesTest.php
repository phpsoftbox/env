<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests;

use PhpSoftBox\Env\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Variables::class)]
final class VariablesTest extends TestCase
{
    /**
     * Проверяет, что кастинг-хелперы возвращают ожидаемые значения.
     */
    #[Test]
    public function castsValues(): void
    {
        $vars = Variables::fromArray([
            'INT' => '10',
            'FLOAT' => '1.5',
            'BOOL_TRUE' => 'true',
            'BOOL_FALSE' => '0',
            'LIST' => 'a, b, c',
            'JSON' => '["x", "y"]',
        ]);

        self::assertSame(10, $vars->toInt('INT'));
        self::assertSame(1.5, $vars->toFloat('FLOAT'));
        self::assertTrue($vars->toBool('BOOL_TRUE'));
        self::assertFalse($vars->toBool('BOOL_FALSE'));
        self::assertSame(['a', 'b', 'c'], $vars->toArray('LIST'));
        self::assertSame(['x', 'y'], $vars->toArray('JSON'));
    }

    /**
     * Проверяет, что merge перезаписывает значения правой стороны.
     */
    #[Test]
    public function mergeOverridesValues(): void
    {
        $left = Variables::fromArray(['A' => '1', 'B' => '2']);
        $right = Variables::fromArray(['B' => '3', 'C' => '4']);

        $merged = $left->merge($right);

        self::assertSame('1', $merged->get('A'));
        self::assertSame('3', $merged->get('B'));
        self::assertSame('4', $merged->get('C'));
    }
}
