<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Core\Context;
use ChatFlow\I18n\LocaleResolverInterface;

/**
 * Resolves the locale of the event once and puts it on the context, so `$ctx->t()` and every
 * handler below use the same language.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LocaleResolverInterface $resolver) {}

    public function process(Context $ctx, callable $next): mixed
    {
        $locale = $this->resolver->resolve($ctx);

        if ($locale !== null && $locale !== '') {
            $ctx->setLocale($locale);
        }

        return $next($ctx);
    }
}
