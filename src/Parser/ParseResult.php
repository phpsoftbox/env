<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Parser;

final readonly class ParseResult
{
    /**
     * @param array<string, string> $values
     * @param array<string, bool> $exportable
     */
    public function __construct(
        public array $values,
        public array $exportable,
    ) {
    }
}
