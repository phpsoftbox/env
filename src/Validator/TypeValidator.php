<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Validator;

use PhpSoftBox\Env\EnvTypeEnum;
use PhpSoftBox\Env\Exception\ValidationException;
use PhpSoftBox\Env\Variables;

use function implode;

final readonly class TypeValidator implements ValidatorInterface
{
    /**
     * @param array<string, EnvTypeEnum|string> $types
     */
    public function __construct(
        private array $types,
    ) {
    }

    public function validate(Variables $variables): void
    {
        $errors = [];

        foreach ($this->types as $key => $type) {
            if (!$variables->has($key)) {
                continue;
            }

            $envType = EnvTypeEnum::fromMixed($type);
            $valid = match ($envType) {
                EnvTypeEnum::INT => $variables->toInt($key) !== null,
                EnvTypeEnum::FLOAT => $variables->toFloat($key) !== null,
                EnvTypeEnum::BOOL => $variables->toBool($key) !== null,
                EnvTypeEnum::ARRAY => $variables->toArray($key) !== null,
                EnvTypeEnum::STRING => $variables->toString($key) !== null,
                default => false,
            };

            if (!$valid) {
                $errors[] = $key . ':' . ($envType?->value ?? (string) $type);
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Env variables have invalid types: ' . implode(', ', $errors));
        }
    }
}
