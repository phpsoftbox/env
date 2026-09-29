<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Parser;

use InvalidArgumentException;
use PhpSoftBox\Env\Exception\EnvException;

use function count;
use function explode;
use function file;
use function filemtime;
use function ltrim;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const FILE_IGNORE_NEW_LINES;

final class DotenvParser implements ParserInterface
{
    /**
     * @var array<string, array{mtime:int,lines:list<string>}>
     */
    private array $cache = [];

    public function parse(string $path, array $context = []): ParseResult
    {
        $lines      = $this->readLines($path);
        $values     = [];
        $exportable = [];

        $lineCount = count($lines);
        for ($index = 0; $index < $lineCount; $index++) {
            $rawLine = $lines[$index];
            $line    = ltrim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $scope = 'export';
            if (str_starts_with($line, 'export ')) {
                $line  = ltrim(substr($line, 7));
                $scope = 'export';
            } elseif (str_starts_with($line, 'local ')) {
                $line  = ltrim(substr($line, 6));
                $scope = 'local';
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $valuePart] = explode('=', $line, 2);
            $name               = trim($name);

            if ($name === '') {
                continue;
            }

            $value = $this->parseValue($valuePart, $lines, $index, $context, $path);

            $values[$name]     = $value;
            $exportable[$name] = $scope !== 'local';
            $context[$name]    = $value;
        }

        return new ParseResult($values, $exportable);
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $context
     */
    private function parseValue(string $valuePart, array $lines, int &$index, array $context, string $path): string
    {
        $value = ltrim($valuePart);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first === '"' || $first === "'") {
            return $this->parseQuotedValue(substr($value, 1), $first, $lines, $index, $context, $path);
        }

        if ($this->looksLikeMultilineBlock($value)) {
            return $this->parseMultilineBlock($value, $lines, $index);
        }

        $value = $this->stripInlineComment($value);
        $value = trim($value);

        return $this->interpolate($value, $context);
    }

    /**
     * Однопроходный разбор значения в кавычках: escape-последовательности, интерполяция (только для двойных
     * кавычек) и поиск закрывающей кавычки выполняются за один проход, поэтому `\\` перед кавычкой не экранирует её.
     * Значение может продолжаться на следующих строках до закрывающей кавычки; текст после неё игнорируется.
     *
     * @param list<string> $lines
     * @param array<string, string> $context
     *
     * @throws EnvException Если закрывающая кавычка не найдена до конца файла.
     */
    private function parseQuotedValue(
        string $value,
        string $quote,
        array $lines,
        int &$index,
        array $context,
        string $path,
    ): string {
        $startLine = $index + 1;
        $lineCount = count($lines);
        $double    = $quote === '"';
        $buffer    = '';

        while (true) {
            $length = strlen($value);
            for ($i = 0; $i < $length; $i++) {
                $char = $value[$i];

                if ($char === $quote) {
                    return $buffer;
                }

                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $this->unescape($value[$i + 1], $double);
                    $i++;
                    continue;
                }

                if ($double && $char === '$') {
                    $reference = $this->resolveReference($value, $i, $context, $quote);
                    if ($reference !== null) {
                        [$replacement, $i] = $reference;
                        $buffer .= $replacement;
                        continue;
                    }
                }

                $buffer .= $char;
            }

            $index++;
            if ($index >= $lineCount) {
                throw new EnvException(sprintf(
                    'Unclosed %s quote in env file %s on line %d.',
                    $double ? 'double' : 'single',
                    $path,
                    $startLine,
                ));
            }

            $buffer .= "\n";
            $value = $lines[$index];
        }
    }

    /**
     * Escape-последовательности: в двойных кавычках — `\\`, `\"`, `\n`, `\r`, `\t`, `\$`;
     * в одинарных — `\\` и `\'`. Неизвестная последовательность сохраняется как есть (с обратным слешем).
     */
    private function unescape(string $char, bool $double): string
    {
        if ($double) {
            return match ($char) {
                'n'            => "\n",
                'r'            => "\r",
                't'            => "\t",
                '"', '\\', '$' => $char,
                default        => '\\' . $char,
            };
        }

        return match ($char) {
            "'", '\\' => $char,
            default   => '\\' . $char,
        };
    }

    private function stripInlineComment(string $value): string
    {
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '#' && ($i === 0 || $value[$i - 1] === ' ' || $value[$i - 1] === "\t")) {
                return rtrim(substr($value, 0, $i));
            }
        }

        return rtrim($value);
    }

    /**
     * Интерполяция значения без кавычек: `$NAME`, `${NAME}`, `${NAME:-default}`, `${NAME:+alt}`;
     * `\$` даёт литерал `$`, остальные обратные слеши сохраняются.
     *
     * @param array<string, string> $context
     */
    private function interpolate(string $value, array $context): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && ($value[$i + 1] ?? '') === '$') {
                $result .= '$';
                $i++;
                continue;
            }

            if ($char === '$') {
                $reference = $this->resolveReference($value, $i, $context);
                if ($reference !== null) {
                    [$replacement, $i] = $reference;
                    $result .= $replacement;
                    continue;
                }
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * Разбирает ссылку на переменную, начинающуюся с `$` в позиции $offset.
     * Выражение `${...}` заканчивается на первой `}` (вложенные подстановки не поддерживаются).
     *
     * @param array<string, string> $context
     * @return array{0: string, 1: int}|null Подстановка и индекс последнего символа ссылки; null — это не ссылка.
     */
    private function resolveReference(string $value, int $offset, array $context, ?string $quote = null): ?array
    {
        if (($value[$offset + 1] ?? '') === '{') {
            $end = strpos($value, '}', $offset + 2);
            if ($end === false) {
                return null;
            }

            $expr = substr($value, $offset + 2, $end - $offset - 2);
            if ($expr === '' || ($quote !== null && str_contains($expr, $quote))) {
                return null;
            }

            return [$this->resolveExpression($expr, $context), $end];
        }

        if (preg_match('/[A-Za-z0-9_]+/A', $value, $matches, 0, $offset + 1) === 1) {
            return [$context[$matches[0]] ?? '', $offset + strlen($matches[0])];
        }

        return null;
    }

    /**
     * @param array<string, string> $context
     */
    private function resolveExpression(string $expr, array $context): string
    {
        if (preg_match('/^([A-Za-z0-9_]+)(:-|:\+)(.*)$/s', $expr, $parts) === 1) {
            $current = $context[$parts[1]] ?? '';

            if ($parts[2] === ':-') {
                return $current === '' ? $parts[3] : $current;
            }

            return $current === '' ? '' : $parts[3];
        }

        return $context[$expr] ?? '';
    }

    /**
     * @param list<string> $lines
     */
    private function parseMultilineBlock(string $value, array $lines, int &$index): string
    {
        $buffer = rtrim($value, "\r\n");

        $count = count($lines);
        while (++$index < $count) {
            $line = rtrim($lines[$index], "\r\n");
            $buffer .= "\n" . $line;

            if (str_starts_with(trim($line), '-----END ')) {
                break;
            }
        }

        return $buffer;
    }

    private function looksLikeMultilineBlock(string $value): bool
    {
        $trimmed = trim($value);

        return str_starts_with($trimmed, '-----BEGIN ') && !str_contains($trimmed, '-----END ');
    }

    /**
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        $mtime = filemtime($path);
        if ($mtime !== false && isset($this->cache[$path]) && $this->cache[$path]['mtime'] === $mtime) {
            return $this->cache[$path]['lines'];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new InvalidArgumentException('Failed to read env file: ' . $path);
        }

        $this->cache[$path] = [
            'mtime' => $mtime === false ? 0 : $mtime,
            'lines' => $lines,
        ];

        return $lines;
    }
}
