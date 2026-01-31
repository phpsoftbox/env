<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use function strtolower;
use function trim;

enum EnvTypeEnum: string
{
    case INT = 'int';
    case FLOAT = 'float';
    case BOOL = 'bool';
    case ARRAY = 'array';
    case STRING = 'string';

    public static function fromMixed(self|string $type): ?self
    {
        if ($type instanceof self) {
            return $type;
        }

        $normalized = strtolower(trim($type));

        return self::tryFrom($normalized);
    }
}
