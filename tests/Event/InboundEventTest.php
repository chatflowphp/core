<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Event;

use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InboundEventTest extends TestCase
{
    public function testPayloadAndMetadataAreNormalizedOnce(): void
    {
        $event = new InboundEvent(
            conversation: new ConversationRef('42', 'telegram', ['chat' => ['id' => 42]]),
            user: new UserRef(7, 'telegram', ['name' => 'Alex']),
            text: 'hi',
            actionId: 'menu:open',
            actionPayload: ['status' => Status::Open],
            attachments: [new InboundAttachment('photo', 'file', meta: ['width' => 10])],
            messageRef: new MessageRef('10', null, 'cb', ['message_id' => 10]),
            metadata: ['update_id' => 1, 'kind' => Status::Open],
        );

        self::assertSame('42', $event->getConversationId());
        self::assertSame(7, $event->getUserId());
        self::assertTrue($event->isAction());
        self::assertSame(['status' => 'open'], $event->getActionPayload());
        self::assertSame(['update_id' => 1, 'kind' => 'open'], $event->getMetadata());
        self::assertSame(['chat' => ['id' => 42]], $event->getConversation()->getMeta());
        self::assertSame(['name' => 'Alex'], $event->getUser()?->getMeta());
        self::assertSame(10, $event->getMessageRef()?->get('message_id'));
        self::assertSame(10, $event->getAttachments()[0]->get('width'));
        self::assertNull($event->getAttachments()[0]->get('height'));
    }

    public function testEmptyActionIdIsNotAnAction(): void
    {
        $event = new InboundEvent(new ConversationRef('1'), actionId: '');

        self::assertFalse($event->isAction());
        self::assertNull($event->getUserId());
    }

    public function testObjectsInPayloadAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InboundEvent(new ConversationRef('1'), actionPayload: new \stdClass());
    }

    public function testConversationIdMustNotBeBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConversationRef('  ');
    }

    public function testUserIdMustNotBeBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UserRef('');
    }

    public function testAttachmentTypeMustNotBeBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InboundAttachment(' ');
    }

    public function testConversationRefFromId(): void
    {
        self::assertSame('5', ConversationRef::fromId(5, 'test')->getId());
        self::assertSame('test', ConversationRef::fromId(5, 'test')->getPlatform());
    }
}

enum Status: string
{
    case Open = 'open';
}
