<?php

declare(strict_types=1);

namespace PhpSoftBox\Env\Validator;

use PhpSoftBox\Env\Variables;

interface ValidatorInterface
{
    public function validate(Variables $variables): void;
}
