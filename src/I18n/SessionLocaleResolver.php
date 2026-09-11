<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

use ChatFlow\Core\Context;

/**
 * Reads the locale the user chose from the conversation session. Write it with
 * `$ctx->session()->set('locale', 'ru')` when the user picks a language.
 */
final class SessionLocaleResolver implements LocaleResolverInterface
{
    public function __construct(private readonly string $key = 'locale') {}

    public function resolve(Context $ctx): ?string
    {
        if (!$ctx->hasConversation()) {
            return null;
        }

        $locale = $ctx->session()->getString($this->key);

        return $locale !== '' ? $locale : null;
    }
}
