<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

interface TranslatorInterface
{
    /**
     * @param array<string, string> $replace
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): string;

    public function setLocale(string $locale): void;

    public function getLocale(): string;
}
