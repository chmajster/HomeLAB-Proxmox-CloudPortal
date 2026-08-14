<?php

declare(strict_types=1);

namespace CloudPortal\Support;

final class Config
{
    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $root): self
    {
        $defaults = require $root . '/config/defaults.php';
        $runtime = $root . '/config/runtime.php';
        if (is_file($runtime)) {
            $local = require $runtime;
            if (!is_array($local)) {
                throw new \RuntimeException('Invalid runtime configuration.');
            }
            $defaults = array_replace_recursive($defaults, $local);
        }

        return new self($defaults);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
