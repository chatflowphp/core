<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Core\Context;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\DeliveryResult;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Platform\PlatformCapabilities;
use InvalidArgumentException;

final class FakePlatformAdapter implements PlatformAdapterInterface
{
    /** @var list<string> */
    public array $deliveries = [];

    /** @var list<\ChatFlow\View\View> */
    public array $replies = [];

    /** @var list<\ChatFlow\View\View> */
    public array $renders = [];

    /** @var list<array{text: ?string, error: bool}> */
    public array $acks = [];

    /** @var list<string> */
    public array $downloads = [];

    public function __construct(
        private readonly ?PlatformCapabilities $platformCapabilities = null,
        private readonly ?string $failEffectType = null,
    ) {}

    public function createInboundEvent(mixed $input): InboundEventInterface
    {
        if (!$input instanceof InboundEventInterface) {
            throw new InvalidArgumentException('FakePlatformAdapter expects an InboundEventInterface instance.');
        }

        return $input;
    }

    public function deliver(Context $context, OutboundEffectInterface $effect): DeliveryResult
    {
        $this->deliveries[] = $effect->getType();

        if ($this->failEffectType === $effect->getType()) {
            return DeliveryResult::error('forced delivery failure');
        }

        if ($effect instanceof ReplyEffect) {
            $this->replies[] = $effect->getView();

            return DeliveryResult::success('reply_delivered');
        }

        if ($effect instanceof RenderEffect) {
            $this->renders[] = $effect->getView();

            return DeliveryResult::success('render_delivered');
        }

        if ($effect instanceof AckEffect) {
            $this->acks[] = [
                'text' => $effect->getText(),
                'error' => $effect->isError(),
            ];

            return DeliveryResult::success('ack_delivered');
        }

        return DeliveryResult::error('Unsupported effect.');
    }

    public function downloadAttachment(Context $context, string $destinationDir): ?string
    {
        if ($context->getAttachments() === []) {
            return null;
        }

        $path = rtrim($destinationDir, '/') . '/fake-download.bin';
        $this->downloads[] = $path;

        return $path;
    }

    public function capabilities(): PlatformCapabilities
    {
        return $this->platformCapabilities ?? new PlatformCapabilities(
            actions: true,
            choices: true,
            media: true,
            screenRender: true,
            ack: true,
            attachmentDownload: true,
            extensions: ['fake' => true],
        );
    }
}
