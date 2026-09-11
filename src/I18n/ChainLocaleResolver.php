<?php

declare(strict_types=1);

namespace ChatFlow\I18n;

use ChatFlow\Core\Context;

/**
 * Asks each resolver in order and takes the first answer: typically the stored preference first,
 * the language reported by the platform second.
 */
final class ChainLocaleResolver implements LocaleResolverInterface
{
    /**
     * @var list<LocaleResolverInterface>
     */
    private readonly array $resolvers;

    public function __construct(LocaleResolverInterface ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(Context $ctx): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $locale = $resolver->resolve($ctx);

            if ($locale !== null && $locale !== '') {
                return $locale;
            }
        }

        return null;
    }
}
