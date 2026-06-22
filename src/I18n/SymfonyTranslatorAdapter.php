<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

use Symfony\Component\Translation\Translator;

class SymfonyTranslatorAdapter implements TranslatorInterface
{
    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param array<string, string> $replace
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return $this->translator->trans($key, $replace, null, $locale);
    }

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }
}
