<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Validator;

use PhpSoftBox\Env\Exception\ValidationException;
use PhpSoftBox\Env\Variables;

use function implode;

final readonly class RequiredValidator implements ValidatorInterface
{
    /**
     * @param list<string> $required
     */
    public function __construct(
        private array $required,
    ) {
    }

    public function validate(Variables $variables): void
    {
        $missing = [];

        foreach ($this->required as $key) {
            if ($key === '') {
                continue;
            }

            $value = $variables->get($key);
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ValidationException('Required env variables are missing: ' . implode(', ', $missing));
        }
    }
}
