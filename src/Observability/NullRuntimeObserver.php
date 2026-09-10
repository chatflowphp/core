<?php

declare(strict_types=1);

namespace ChatFlow\Observability;

final class NullRuntimeObserver implements RuntimeObserverInterface
{
    public function record(RuntimeEvent $event): void {}
}
