<?php

declare(strict_types=1);

namespace ChatFlow\Observability;

interface RuntimeObserverInterface
{
    public function record(RuntimeEvent $event): void;
}
