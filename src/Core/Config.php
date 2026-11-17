<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(string $configPath)
    {
        foreach (glob(rtrim($configPath, '/\\') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $data = include $file;
            if (is_array($data)) {
                $this->items[$key] = $data;
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->items;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
