<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Contracts;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Core\Context;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\MessageRef;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\View\View;
use PHPUnit\Framework\Assert;

final class PlatformAdapterContractAssertions
{
    public static function assertInboundEventSatisfiesCoreContract(InboundEventInterface $event): void
    {
        Assert::assertNotSame('', $event->getConversationId());
        Assert::assertSame($event->getConversation()->getId(), $event->getConversationId());

        if ($event->getUser() !== null) {
            Assert::assertSame($event->getUser()->getId(), $event->getUserId());
        }

        foreach ($event->getAttachments() as $attachment) {
            Assert::assertInstanceOf(InboundAttachment::class, $attachment);
            Assert::assertNotSame('', $attachment->getType());
        }

        if ($event->getMessageRef() !== null) {
            Assert::assertInstanceOf(MessageRef::class, $event->getMessageRef());
        }
    }

    public static function assertAdapterDeclaresSerializableCapabilities(PlatformAdapterInterface $adapter): void
    {
        $capabilities = $adapter->capabilities()->toArray();

        foreach (['actions', 'choices', 'media', 'screen_render', 'ack', 'attachment_download'] as $key) {
            Assert::assertArrayHasKey($key, $capabilities);
        }

        Assert::assertArrayHasKey('extensions', $capabilities);
    }

    public static function assertAdapterDeliversCoreEffects(PlatformAdapterInterface $adapter, Context $context): void
    {
        Assert::assertTrue($adapter->deliver($context, new ReplyEffect(View::text('contract reply')))->isSuccess());
        Assert::assertTrue($adapter->deliver($context, new RenderEffect(View::text('contract render')))->isSuccess());
        Assert::assertTrue($adapter->deliver($context, new AckEffect('contract ack'))->isSuccess());
    }
}
