<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Tests\Parser;

use PhpSoftBox\Env\Exception\EnvException;
use PhpSoftBox\Env\Parser\DotenvParser;
use PhpSoftBox\Env\Tests\Support\EnvFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;

#[CoversClass(DotenvParser::class)]
#[CoversMethod(DotenvParser::class, 'parse')]
final class DotenvParserTest extends TestCase
{
    use EnvFixtures;

    /**
     * Проверим, что кавычка после экранированного обратного слеша (`"dir\\"`) закрывает значение
     * и следующая переменная не попадает в предыдущую.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function quoteAfterEscapedBackslashClosesValue(): void
    {
        $values = $this->parseLines([
            'B="dir\\\\"',
            'C=next',
        ]);

        self::assertSame(['B' => 'dir\\', 'C' => 'next'], $values);
    }

    /**
     * Проверим, что в одинарных кавычках `'dir\\'` также закрывается на кавычке после двойного слеша.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function singleQuoteAfterEscapedBackslashClosesValue(): void
    {
        $values = $this->parseLines([
            "B='dir\\\\'",
            'C=next',
        ]);

        self::assertSame(['B' => 'dir\\', 'C' => 'next'], $values);
    }

    /**
     * Проверим, что экранированная кавычка (нечётное число слешей) не закрывает значение.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function escapedQuoteDoesNotCloseValue(): void
    {
        $values = $this->parseLines([
            'A="say \\"hi\\" \\\\\\"x"',
        ]);

        self::assertSame('say "hi" \\"x', $values['A']);
    }

    /**
     * Проверим, что незакрытая кавычка приводит к исключению с номером строки начала значения.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function unclosedQuoteThrowsWithLineNumber(): void
    {
        $path = $this->putFile($this->makeTempDir(), '.env', implode("\n", [
            'A=1',
            'B="unclosed',
            'C=3',
            '',
        ]));

        $this->expectException(EnvException::class);
        $this->expectExceptionMessage('on line 2');

        new DotenvParser()->parse($path);
    }

    /**
     * Проверим, что значение в кавычках может продолжаться на следующих строках до закрывающей кавычки.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function quotedValueSpansMultipleLines(): void
    {
        $values = $this->parseLines([
            'A="line1',
            'line2"',
            'B=2',
        ]);

        self::assertSame(['A' => "line1\nline2", 'B' => '2'], $values);
    }

    /**
     * Проверим, что escape-последовательности разбираются за один проход: `"C:\\new"` не даёт перевода строки.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function escapedBackslashIsNotFollowedByNewline(): void
    {
        $values = $this->parseLines([
            'PATH_WIN="C:\\\\new"',
        ]);

        self::assertSame('C:\\new', $values['PATH_WIN']);
    }

    /**
     * Проверим весь набор escape-последовательностей двойных кавычек; неизвестная сохраняется как есть.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function doubleQuotedEscapeSequences(): void
    {
        $values = $this->parseLines([
            'A="n\\nr\\rt\\tq\\"b\\\\d\\$HOME u\\q"',
        ]);

        self::assertSame("n\nr\rt\tq\"b\\d\$HOME u\\q", $values['A']);
    }

    /**
     * Проверим, что в одинарных кавычках нет интерполяции и `\n` остаётся литералом.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function singleQuotedValueIsLiteral(): void
    {
        $values = $this->parseLines([
            'HOST=localhost',
            "A='\$HOST\\n\\'x\\''",
        ]);

        self::assertSame("\$HOST\\n'x'", $values['A']);
    }

    /**
     * Проверим, что экранированный `\$` в значении без кавычек не интерполируется.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function escapedDollarInUnquotedValueIsLiteral(): void
    {
        $values = $this->parseLines([
            'HOST=localhost',
            'A=\\$HOST-$HOST',
        ]);

        self::assertSame('$HOST-localhost', $values['A']);
    }

    /**
     * Проверим интерполяцию в двойных кавычках: `${NAME}`, `$NAME`, `:-` и `:+`.
     *
     * @see DotenvParser::parse()
     */
    #[Test]
    public function interpolatesInsideDoubleQuotes(): void
    {
        $values = $this->parseLines([
            'HOST=localhost',
            'A="${HOST}:$HOST ${MISSING:-def} ${HOST:+alt}"',
        ]);

        self::assertSame('localhost:localhost def alt', $values['A']);
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private function parseLines(array $lines): array
    {
        $path = $this->putFile($this->makeTempDir(), '.env', implode("\n", $lines) . "\n");

        return new DotenvParser()->parse($path)->values;
    }
}
