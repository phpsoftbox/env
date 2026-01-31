<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Reader;

use PhpSoftBox\Env\Variables;

interface ReaderInterface
{
    /**
     * @return list<string>
     */
    public function files(?string $environment = null): array;

    public function read(
        ?string $environment = null,
        bool $includeGlobals = true,
        bool $overload = false,
        ?string $prefix = null,
        bool $strict = true,
    ): Variables;
}
