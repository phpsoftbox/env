<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Parser;

interface ParserInterface
{
    /**
     * @param array<string, string> $context
     */
    public function parse(string $path, array $context = []): ParseResult;
}
