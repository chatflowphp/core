<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Tests\Support\HookLog;

final class CustomMiddlewareScene extends BaseScene
{
    public function __construct(private readonly HookLog $log) {}

    public function getMiddlewares(): array
    {
        return [new class ($this->log) implements MiddlewareInterface {
            public function __construct(private readonly HookLog $log) {}

            public function process(Context $ctx, callable $next): mixed
            {
                $this->log->add('scene-middleware');

                return $next($ctx);
            }
        }];
    }

    public function handle(Context $ctx): void
    {
        $this->log->add('CustomMiddlewareScene:handle');
    }
}
