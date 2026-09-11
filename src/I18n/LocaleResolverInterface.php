<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

use ChatFlow\Core\Context;

/**
 * Decides which locale an inbound event should be handled in. Platform adapters provide resolvers
 * for the language the platform reports; applications add their own for a stored preference.
 */
interface LocaleResolverInterface
{
    /**
     * Returns the locale for this event, or null when this resolver has no opinion.
     */
    public function resolve(Context $ctx): ?string;
}
