<?php

declare(strict_types=1);

namespace ChatFlow\Platform;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\AfterHandleInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Outbound\DeliveryResult;
use ChatFlow\Outbound\OutboundEffectInterface;

/**
 * Wraps a real adapter so the application hears the platform but never answers it: inbound
 * events are parsed as usual, outbound effects are recorded and reported delivered. Use it to run
 * a new bot in the shadow of a live one and compare what it would have said.
 *
 * @phpstan-type RecordedEffect array{conversation: string, effect: OutboundEffectInterface}
 */
final class ListeningPlatformAdapter implements PlatformAdapterInterface, RuntimeDependencyBinderInterface, AfterHandleInterface
{
    /**
     * @var list<RecordedEffect>
     */
    private array $effects = [];

    /**
     * @var callable(Context, OutboundEffectInterface): void|null
     */
    private $recorder;

    /**
     * @param callable(Context, OutboundEffectInterface): void|null $recorder Receives every effect as it is "delivered"
     */
    public function __construct(
        private readonly PlatformAdapterInterface $inner,
        ?callable $recorder = null,
    ) {
        $this->recorder = $recorder;
    }

    public function createInboundEvent(mixed $input): InboundEventInterface
    {
        return $this->inner->createInboundEvent($input);
    }

    public function deliver(Context $context, OutboundEffectInterface $effect): DeliveryResult
    {
        $this->effects[] = ['conversation' => $context->getConversationId(), 'effect' => $effect];

        if ($this->recorder !== null) {
            ($this->recorder)($context, $effect);
        }

        return DeliveryResult::success('recorded');
    }

    public function downloadAttachment(Context $context, string $destinationDir): ?string
    {
        return $this->inner->downloadAttachment($context, $destinationDir);
    }

    public function capabilities(): PlatformCapabilities
    {
        return $this->inner->capabilities();
    }

    public function bindRuntimeDependencies(ContainerInterface $container, Context $context): void
    {
        if ($this->inner instanceof RuntimeDependencyBinderInterface) {
            $this->inner->bindRuntimeDependencies($container, $context);
        }
    }

    public function afterHandle(Context $context, Result $result): void
    {
        // The inner adapter's hook answers the platform (callback queries and the like); a
        // listener must stay silent, so it is not called.
    }

    /**
     * @return list<RecordedEffect>
     */
    public function getEffects(): array
    {
        return $this->effects;
    }

    public function clear(): void
    {
        $this->effects = [];
    }
}
