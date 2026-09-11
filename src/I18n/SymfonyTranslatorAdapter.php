<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorInterface;

/**
 * Adapter over a `symfony/translation` translator, so an application that already has a catalogue
 * keeps using it. Requires `symfony/translation-contracts`.
 *
 * Parameters are handed to Symfony unchanged: use the placeholder style of your own catalogue
 * ("%name%"), not the "{name}" style of ArrayTranslator.
 */
final class SymfonyTranslatorAdapter implements TranslatorInterface
{
    public function __construct(
        private readonly SymfonyTranslatorInterface $translator,
        private readonly ?string $domain = null,
    ) {}

    public function trans(string $id, array $parameters = [], ?string $locale = null): string
    {
        return $this->translator->trans($id, $parameters, $this->domain, $locale);
    }
}
