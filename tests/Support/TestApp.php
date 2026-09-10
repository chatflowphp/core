<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

use ChatFlow\Container\Container;
use ChatFlow\Core\Application;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\UserRef;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Scene\ConversationManager;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Clock\ClockInterface;

/**
 * Builds a core Application on top of the fake platform adapter.
 */
final class TestApp
{
    public static function create(
        ?FakePlatformAdapter $adapter = null,
        ?StorageInterface $storage = null,
        ?RuntimeObserverInterface $observer = null,
        ?Container $container = null,
        ?ClockInterface $clock = null,
        ?int $sessionTtlSeconds = null,
    ): Application {
        $container ??= new Container();
        $scenes = new SceneRegistry($container);
        $validation = new ValidationRegistry($container);
        $conversations = new ConversationManager(
            $scenes,
            $validation,
            $storage ?? new MemoryStorage(),
            new SceneTransitions($scenes),
            $sessionTtlSeconds,
            $clock,
            $observer,
        );

        return new Application(
            adapter: $adapter ?? new FakePlatformAdapter(),
            container: $container,
            conversations: $conversations,
            validationRegistry: $validation,
            runtimeObserver: $observer,
        );
    }

    /**
     * @param list<InboundAttachment> $attachments
     */
    public static function event(
        string $conversationId,
        string $text = '',
        ?string $actionId = null,
        mixed $payload = null,
        array $attachments = [],
        ?int $userId = 1,
    ): InboundEvent {
        return new InboundEvent(
            conversation: new ConversationRef($conversationId),
            user: $userId === null ? null : new UserRef($userId),
            text: $text,
            actionId: $actionId,
            actionPayload: $payload,
            attachments: $attachments,
        );
    }
}
