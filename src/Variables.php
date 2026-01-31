<?php

declare(strict_types=1);

namespace PhpSoftBox\Env;

use InvalidArgumentException;
use PhpSoftBox\Collection\Collection;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function explode;
use function ini_get;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final class Variables
{
    private Collection $variables;
    /**
     * @var array<string, bool>
     */
    private array $exportable;
    private ?string $prefix;

    private function __construct(Collection $variables, array $exportable, ?string $prefix)
    {
        $this->variables  = $variables;
        $this->exportable = $exportable;
        $this->prefix     = $this->normalizePrefix($prefix);
    }

    public static function fromArray(array $variables, ?string $prefix = null): self
    {
        return self::fromParsed($variables, [], $prefix, true);
    }

    public static function fromGlobals(?string $prefix = null): self
    {
        $data = [];

        $env = $GLOBALS['_ENV'] ?? null;
        if (is_array($env)) {
            foreach ($env as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $data[$key] = (string) $value;
                }
            }
        }

        $server = $GLOBALS['_SERVER'] ?? null;
        if (is_array($server)) {
            foreach ($server as $key => $value) {
                if (is_string($key) && is_scalar($value) && !array_key_exists($key, $data)) {
                    $data[$key] = (string) $value;
                }
            }
        }

        return self::fromArray($data, $prefix);
    }

    public static function empty(?string $prefix = null): self
    {
        return new self(new Collection(), [], $prefix);
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, bool> $exportable
     */
    public static function fromParsed(array $variables, array $exportable, ?string $prefix = null, bool $defaultExportable = false): self
    {
        $normalized = [];
        $exportMap  = [];
        $prefix     = self::normalizePrefixStatic($prefix);

        foreach ($variables as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = self::applyPrefixStatic($key, $prefix);
            $normalized[$normalizedKey] = (string) $value;

            if (array_key_exists($key, $exportable)) {
                $exportMap[$normalizedKey] = (bool) $exportable[$key];
            } else {
                $exportMap[$normalizedKey] = $defaultExportable;
            }
        }

        return new self(new Collection($normalized), $exportMap, $prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->variables->get($this->normalizeKey($key), $default);
    }

    public function has(string $key): bool
    {
        return $this->variables->has($this->normalizeKey($key));
    }

    public function all(): array
    {
        return $this->variables->all();
    }

    public function merge(self $variables): self
    {
        $this->assertCompatiblePrefix($variables);

        $merged = $this->variables->merge($variables->all(), false);
        $exportable = $this->exportable;

        foreach ($variables->exportable as $key => $flag) {
            $exportable[$key] = $flag;
        }

        return new self($merged, $exportable, $this->prefix);
    }

    public function filter(callable $callback): self
    {
        $items = [];
        $exportable = [];

        foreach ($this->variables->all() as $key => $value) {
            if ($callback($value, $key) === true) {
                $items[$key] = $value;
                if (array_key_exists($key, $this->exportable)) {
                    $exportable[$key] = $this->exportable[$key];
                }
            }
        }

        return new self(new Collection($items), $exportable, $this->prefix);
    }

    public function map(callable $callback): self
    {
        $items = [];

        foreach ($this->variables->all() as $key => $value) {
            $items[$key] = $callback($value, $key);
        }

        return new self(new Collection($items), $this->exportable, $this->prefix);
    }

    public function copy(): self
    {
        return new self(new Collection($this->variables->all()), $this->exportable, $this->prefix);
    }

    public function toGlobals(bool $includeLocal = false): void
    {
        $varsOrder = (string) ini_get('variables_order');
        $envAvailable = isset($GLOBALS['_ENV']) && is_array($GLOBALS['_ENV']) && str_contains($varsOrder, 'E');

        if ($envAvailable) {
            foreach ($this->exportablePairs($includeLocal) as $key => $value) {
                $GLOBALS['_ENV'][(string) $key] = (string) $value;
            }

            return;
        }

        $this->toServer($includeLocal);
    }

    public function toServer(bool $includeLocal = false): void
    {
        if (!isset($GLOBALS['_SERVER']) || !is_array($GLOBALS['_SERVER'])) {
            $GLOBALS['_SERVER'] = [];
        }

        foreach ($this->exportablePairs($includeLocal) as $key => $value) {
            $GLOBALS['_SERVER'][(string) $key] = (string) $value;
        }
    }

    public function toPutEnv(bool $includeLocal = false): void
    {
        foreach ($this->exportablePairs($includeLocal) as $key => $value) {
            putenv((string) $key . '=' . (string) $value);
        }
    }

    public function toString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return (string) $value;
    }

    public function toInt(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function toFloat(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    public function toBool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        $truthy = ['1', 'true', 'yes', 'on'];
        $falsy  = ['0', 'false', 'no', 'off'];

        if (in_array($normalized, $truthy, true)) {
            return true;
        }

        if (in_array($normalized, $falsy, true)) {
            return false;
        }

        return $default;
    }

    public function toArray(string $key, ?array $default = null): ?array
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }

        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (str_contains($trimmed, ',')) {
            $parts = array_map('trim', explode(',', $trimmed));
            $parts = array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));

            return $parts;
        }

        return $default;
    }

    private function normalizeKey(string $key): string
    {
        $prefix = $this->prefix;
        if ($prefix === null || $prefix === '') {
            return $key;
        }

        if (str_starts_with($key, $prefix)) {
            return $key;
        }

        return $prefix . $key;
    }

    private function assertCompatiblePrefix(self $other): void
    {
        if ($this->prefix !== null && $other->prefix !== null && $this->prefix !== $other->prefix) {
            throw new InvalidArgumentException('Cannot merge Variables with different prefixes.');
        }
    }

    private function exportablePairs(bool $includeLocal): array
    {
        $items = $this->variables->all();
        if ($includeLocal) {
            return $items;
        }

        $exportable = [];
        foreach ($items as $key => $value) {
            if ($this->exportable[$key] ?? false) {
                $exportable[$key] = $value;
            }
        }

        return $exportable;
    }

    private function normalizePrefix(?string $prefix): ?string
    {
        return self::normalizePrefixStatic($prefix);
    }

    private static function normalizePrefixStatic(?string $prefix): ?string
    {
        if ($prefix === null) {
            return null;
        }

        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }

        return $prefix;
    }

    private static function applyPrefixStatic(string $key, ?string $prefix): string
    {
        if ($prefix === null || $prefix === '') {
            return $key;
        }

        if (str_starts_with($key, $prefix)) {
            return $key;
        }

        return $prefix . $key;
    }
}
