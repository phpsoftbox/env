<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use BackedEnum;
use InvalidArgumentException;
use PhpSoftBox\Filter\BooleanFilter;
use PhpSoftBox\Filter\ExplodeFilter;
use PhpSoftBox\Filter\FilterAdapter;
use PhpSoftBox\Filter\FilterInterface;
use PhpSoftBox\Filter\FloatFilter;
use PhpSoftBox\Filter\IntegerFilter;
use PhpSoftBox\Filter\JsonDecodeFilter;
use PhpSoftBox\Filter\ListFilter;
use PhpSoftBox\Filter\StringFilter;
use PhpSoftBox\Filter\TrimFilter;
use UnexpectedValueException;

use function is_a;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;

final readonly class EnvValue
{
    public function __construct(
        private mixed $value,
        private bool $exists,
    ) {
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function raw(): mixed
    {
        return $this->value;
    }

    public function filter(FilterInterface $filter): mixed
    {
        return $filter($this->value);
    }

    public function through(FilterInterface ...$filters): mixed
    {
        return new FilterAdapter()->apply($this->value, $filters);
    }

    public function filtered(FilterInterface ...$filters): self
    {
        return new self($this->through(...$filters), $this->exists);
    }

    public function string(?string $default = null): ?string
    {
        $value = new StringFilter()($this->value);

        return $value ?? $default;
    }

    public function int(?int $default = null): ?int
    {
        return new IntegerFilter($default)($this->value);
    }

    public function float(?float $default = null): ?float
    {
        return new FloatFilter($default)($this->value);
    }

    public function bool(?bool $default = null): ?bool
    {
        return new BooleanFilter($default)($this->value);
    }

    public function array(?array $default = null): ?array
    {
        if (is_array($this->value)) {
            return $this->value;
        }

        if (!is_string($this->value)) {
            return $default;
        }

        $value = new TrimFilter()($this->value);

        if ($value === '') {
            return $default;
        }

        if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            $decoded = new JsonDecodeFilter(default: null)($value);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (!str_contains($value, ',')) {
            return $default;
        }

        return new ListFilter(
            skipEmpty: true,
            itemFilters: [new TrimFilter()],
        )(new ExplodeFilter()($value));
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enumClass
     * @param T|null $default
     * @return T|null
     */
    public function enum(string $enumClass, ?BackedEnum $default = null): ?BackedEnum
    {
        if (!is_a($enumClass, BackedEnum::class, true)) {
            throw new InvalidArgumentException('Environment value target must be a backed enum.');
        }

        if ($default !== null && !$default instanceof $enumClass) {
            throw new InvalidArgumentException('Enum default must be an instance of the target enum.');
        }

        $value = $this->string();
        if ($value === null) {
            return $default;
        }

        $resolved = $enumClass::tryFrom($value);
        if ($resolved === null) {
            throw new UnexpectedValueException(sprintf(
                'Value "%s" is not valid for enum %s.',
                $value,
                $enumClass,
            ));
        }

        return $resolved;
    }
}
