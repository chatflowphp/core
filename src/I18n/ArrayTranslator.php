<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

/**
 * Translator backed by plain arrays: `['ru' => ['menu.title' => 'Меню']]`.
 *
 * Lookup order is the requested locale, its primary subtag ("en-GB" falls back to "en"), then the
 * fallback locale. A missing entry returns the id, so a forgotten translation is visible but never
 * breaks a flow.
 */
final class ArrayTranslator implements TranslatorInterface
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $catalogues;

    /**
     * @param array<string, array<string, string>> $catalogues Locale to message id to text
     */
    public function __construct(array $catalogues = [], private readonly string $fallbackLocale = 'en')
    {
        $this->catalogues = [];

        foreach ($catalogues as $locale => $messages) {
            $this->catalogues[self::normalize($locale)] = $messages;
        }
    }

    /**
     * @param array<string, string> $messages
     */
    public function add(string $locale, array $messages): self
    {
        $locale = self::normalize($locale);
        $this->catalogues[$locale] = [...$this->catalogues[$locale] ?? [], ...$messages];

        return $this;
    }

    public function trans(string $id, array $parameters = [], ?string $locale = null): string
    {
        return self::interpolate($this->lookup($id, $locale), $parameters);
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return array_keys($this->catalogues);
    }

    private function lookup(string $id, ?string $locale): string
    {
        foreach ($this->candidates($locale) as $candidate) {
            if (isset($this->catalogues[$candidate][$id])) {
                return $this->catalogues[$candidate][$id];
            }
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private function candidates(?string $locale): array
    {
        $locale = $locale !== null ? self::normalize($locale) : null;
        $candidates = [];

        if ($locale !== null && $locale !== '') {
            $candidates[] = $locale;
            $primary = self::primarySubtag($locale);

            if ($primary !== $locale) {
                $candidates[] = $primary;
            }
        }

        $candidates[] = self::normalize($this->fallbackLocale);

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<string, string|int|float|bool|null> $parameters
     */
    private static function interpolate(string $text, array $parameters): string
    {
        if ($parameters === []) {
            return $text;
        }

        $replacements = [];

        foreach ($parameters as $key => $value) {
            $replacements['{' . $key . '}'] = match (true) {
                \is_bool($value) => $value ? '1' : '',
                $value === null => '',
                default => (string) $value,
            };
        }

        return strtr($text, $replacements);
    }

    private static function normalize(string $locale): string
    {
        return strtolower(str_replace('_', '-', trim($locale)));
    }

    private static function primarySubtag(string $locale): string
    {
        $position = strpos($locale, '-');

        return $position === false ? $locale : substr($locale, 0, $position);
    }
}
