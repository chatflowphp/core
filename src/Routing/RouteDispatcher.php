<?php

declare(strict_types=1);

namespace ChatFlow\Routing;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;

/**
 * Invokes route handlers through the container so that they receive the request context, the
 * inbound event and any other service by type or by parameter name.
 */
final class RouteDispatcher
{
    /**
     * @throws ContainerException
     */
    public function dispatch(Route $route, Context $ctx): void
    {
        $ctx->getContainer()->call($route->getHandler(), [
            Context::class => $ctx,
            InboundEventInterface::class => $ctx->getEvent(),
            'ctx' => $ctx,
            'context' => $ctx,
            'event' => $ctx->getEvent(),
        ]);
    }
}
