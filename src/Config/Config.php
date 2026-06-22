<?php

declare(strict_types=1);

namespace ChatFlow\Config;

use ChatFlow\Exception\ConfigException;
use Dotenv\Dotenv;
use Throwable;

class Config implements ConfigInterface
{
    private string $basePath;

    /**
     * @throws ConfigException
     */
    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? (string) getcwd();

        try {
            // We use safeLoad to avoid exceptions if .env does not exist,
            // as the application might rely solely on system environment variables.
            $dotenv = Dotenv::createImmutable($this->basePath);
            $dotenv->safeLoad();
        } catch (Throwable $e) {
            throw new ConfigException('Failed to load environment variables', 0, $e);
        }
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Check $_ENV first, then $_SERVER.
        // We avoid getenv() as it is not thread-safe.
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        $filter = filter_var($value, FILTER_VALIDATE_INT);

        return $filter !== false ? $filter : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string>
     *
     * @throws ConfigException
     */
    public function getArray(string $key, array $default = [], string $separator = ','): array
    {
        if ($separator === '') {
            throw new ConfigException('Separator cannot be empty');
        }

        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): string => is_scalar($item) || $item instanceof \Stringable ? (string) $item : '', $value);
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return $default;
        }

        $stringValue = trim((string) $value);

        // Handle empty string explicitly to avoid returning [""]
        if ($stringValue === '') {
            return [];
        }

        return array_map('trim', explode($separator, $stringValue));
    }
}
