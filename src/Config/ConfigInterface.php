<?php

declare(strict_types=1);

namespace ChatFlow\Config;

use ChatFlow\Exception\ConfigException;

interface ConfigInterface
{
    /**
     * Get config value by key.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Check if config key exists.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get string config value.
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function getString(string $key, string $default = ''): string;

    /**
     * Get integer config value.
     *
     * @param string $key
     * @param int    $default
     *
     * @return int
     */
    public function getInt(string $key, int $default = 0): int;

    /**
     * Get boolean config value.
     *
     * @param string $key
     * @param bool   $default
     *
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool;

    /**
     * Get array config value.
     *
     * @param string        $key
     * @param array<string> $default
     * @param string        $separator
     *
     * @return array<string>
     *
     * @throws ConfigException
     */
    public function getArray(string $key, array $default = [], string $separator = ','): array;

    /**
     * Get the base path of the application.
     *
     * Returns the root directory path of the project.
     *
     * @return string The base path of the application
     */
    public function getBasePath(): string;
}
