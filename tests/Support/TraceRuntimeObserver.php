<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;

final class TraceRuntimeObserver implements RuntimeObserverInterface
{
    /** @var list<RuntimeEvent> */
    private array $events = [];

    public function record(RuntimeEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<RuntimeEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_map(static fn(RuntimeEvent $event): string => $event->getName(), $this->events);
    }
}
