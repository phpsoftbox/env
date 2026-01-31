<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Parser;

use InvalidArgumentException;

use function count;
use function explode;
use function file;
use function filemtime;
use function ltrim;
use function preg_match;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

final class DotenvParser implements ParserInterface
{
    /**
     * @var array<string, array{mtime:int,lines:list<string>}>
     */
    private array $cache = [];

    public function parse(string $path, array $context = []): ParseResult
    {
        $lines = $this->readLines($path);
        $values = [];
        $exportable = [];

        $lineCount = count($lines);
        for ($index = 0; $index < $lineCount; $index++) {
            $rawLine = $lines[$index];
            $line = ltrim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $scope = 'export';
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
                $scope = 'export';
            } elseif (str_starts_with($line, 'local ')) {
                $line = ltrim(substr($line, 6));
                $scope = 'local';
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $valuePart] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = $this->parseValue($valuePart, $lines, $index, $context);

            $values[$name] = $value;
            $exportable[$name] = $scope !== 'local';
            $context[$name] = $value;
        }

        return new ParseResult($values, $exportable);
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $context
     */
    private function parseValue(string $valuePart, array $lines, int &$index, array $context): string
    {
        $value = ltrim($valuePart);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first === '"' || $first === "'") {
            return $this->parseQuotedValue($value, $first, $lines, $index, $context);
        }

        if ($this->looksLikeMultilineBlock($value)) {
            return $this->parseMultilineBlock($value, $lines, $index);
        }

        $value = $this->stripInlineComment($value);
        $value = trim($value);

        return $this->interpolate($value, $context);
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $context
     */
    private function parseQuotedValue(string $value, string $quote, array $lines, int &$index, array $context): string
    {
        $value = substr($value, 1);
        $buffer = '';

        while (true) {
            $pos = $this->findClosingQuote($value, $quote);
            if ($pos !== null) {
                $buffer .= substr($value, 0, $pos);
                break;
            }

            $buffer .= $value;
            $index++;
            if (!isset($lines[$index])) {
                break;
            }
            $buffer .= "\n";
            $value = $lines[$index];
        }

        if ($quote === '"') {
            $buffer = $this->unescapeDoubleQuoted($buffer);
            return $this->interpolate($buffer, $context);
        }

        return $this->unescapeSingleQuoted($buffer);
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

    private function interpolate(string $value, array $context): string
    {
        $result = preg_replace_callback(
            '/(?<!\\\\)\$(\{[^}]+\}|[A-Za-z0-9_]+)/',
            static function (array $matches) use ($context): string {
                $token = $matches[1];
                if ($token[0] === '{') {
                    $expr = substr($token, 1, -1);

                    if (preg_match('/^([A-Za-z0-9_]+)(:-|:\+)(.*)$/s', $expr, $parts)) {
                        $name = $parts[1];
                        $operator = $parts[2];
                        $fallback = $parts[3];
                        $current = $context[$name] ?? '';

                        if ($operator === ':-') {
                            return $current === '' ? $fallback : $current;
                        }

                        return $current === '' ? '' : $fallback;
                    }

                    return $context[$expr] ?? '';
                }

                return $context[$token] ?? '';
            },
            $value,
        );

        if ($result === null) {
            throw new InvalidArgumentException('Failed to interpolate env value.');
        }

        return str_replace('\\$', '$', $result);
    }

    private function findClosingQuote(string $value, string $quote): ?int
    {
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                return $i;
            }
        }

        return null;
    }

    private function unescapeDoubleQuoted(string $value): string
    {
        return str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
    }

    private function unescapeSingleQuoted(string $value): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
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
