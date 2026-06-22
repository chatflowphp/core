<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Core\Context;
use ChatFlow\Outbound\DeliveryResult;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Platform\PlatformCapabilities;

interface PlatformAdapterInterface
{
    public function createInboundEvent(mixed $input): InboundEventInterface;

    public function deliver(Context $context, OutboundEffectInterface $effect): DeliveryResult;

    public function downloadAttachment(Context $context, string $destinationDir): ?string;

    public function capabilities(): PlatformCapabilities;
}
