<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

/**
 * Minimal translation contract the runtime depends on. Implementations are adapters over whatever
 * the application already uses; ChatFlow ships an array-backed one and an adapter for
 * `symfony/translation`.
 */
interface TranslatorInterface
{
    /**
     * Returns the translated text, or the id itself when the catalogue has no entry for it.
     *
     * @param array<string, string|int|float|bool|null> $parameters Placeholder values; "{name}" in
     *                                                              the text is replaced by the value of "name"
     */
    public function trans(string $id, array $parameters = [], ?string $locale = null): string;
}
