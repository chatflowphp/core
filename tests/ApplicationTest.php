<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use ChatFlow\Config\Config;
use ChatFlow\Container\Container;
use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\ExceptionRegistry;
use ChatFlow\FSM\SceneRegistry;
use ChatFlow\FSM\StateManager;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Platform\PlatformCapabilities;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Tests\Fakes\FakePlatformAdapter;
use ChatFlow\Tests\Fakes\TraceRuntimeObserver;
use ChatFlow\Tests\Fixtures\SurveyScene;
use ChatFlow\Validation\ValidationRegistry;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApplicationTest extends TestCase
{
    public function test_text_route_uses_fake_adapter_and_middleware_order(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = $this->createContainer();
        $router = new \ChatFlow\Routing\Router();
        $trace = new \ArrayObject();

        $application = new Application(
            adapter: $adapter,
            router: $router,
            container: $container,
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
        );

        $application->middleware([
            new class ($trace) implements MiddlewareInterface {
                public function __construct(private \ArrayObject $trace)
                {
                }

                public function process(Context $ctx, callable $next): mixed
                {
                    $this->trace->append('global-before');
                    $ctx->set('from_middleware', true);
                    $result = $next($ctx);
                    $this->trace->append('global-after');

                    return $result;
                }
            },
        ]);

        $router->onTextPrefix('/hello', function (Context $ctx) use ($trace): void {
            $trace->append($ctx->get('from_middleware') === true ? 'handler' : 'handler-missing');
            $ctx->reply(View::text('Hello route'));
            $ctx->render(View::text('Rendered route'));
            $ctx->ack('route-ack');
        });

        $result = $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-1'),
            user: new UserRef(10),
            text: '/hello'
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame(['global-before', 'handler', 'global-after'], $trace->getArrayCopy());
        self::assertCount(1, $adapter->replies);
        self::assertSame('Hello route', $adapter->replies[0]->getText());
        self::assertCount(1, $adapter->renders);
        self::assertSame('Rendered route', $adapter->renders[0]->getText());
        self::assertSame([['text' => 'route-ack', 'error' => false]], $adapter->acks);
    }

    public function test_action_route_renders_on_fake_adapter(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = $this->createContainer();
        $router = new \ChatFlow\Routing\Router();

        $application = new Application(
            adapter: $adapter,
            router: $router,
            container: $container,
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
        );

        $router->onActionPrefix('menu:', static function (Context $ctx): void {
            $ctx->render(View::text('Menu opened'));
        });

        $result = $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-2'),
            user: new UserRef(20),
            actionId: 'menu:open'
        ));

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $adapter->renders);
        self::assertSame('Menu opened', $adapter->renders[0]->getText());
    }

    public function test_router_supports_command_regex_exact_action_and_fallback_routes(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = new Application(
            adapter: $adapter,
            router: $router = new \ChatFlow\Routing\Router(),
            container: $container = $this->createContainer(),
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
        );

        $trace = [];
        $router->onCommand('start', static function () use (&$trace): void {
            $trace[] = 'command';
        });
        $router->onTextRegex('/^ticket:\\d+$/', static function () use (&$trace): void {
            $trace[] = 'text_regex';
        });
        $router->onAction('support:open', static function () use (&$trace): void {
            $trace[] = 'action';
        });
        $router->onActionRegex('/^support:/', static function () use (&$trace): void {
            $trace[] = 'action_regex';
        });
        $router->fallback(static function () use (&$trace): void {
            $trace[] = 'fallback';
        });

        $application->handle($this->event('conv-r1', text: '/start now'));
        $application->handle($this->event('conv-r1', text: 'ticket:123'));
        $application->handle($this->event('conv-r1', actionId: 'support:open'));
        $application->handle($this->event('conv-r1', actionId: 'support:refresh'));
        $application->handle($this->event('conv-r1', text: 'unknown'));

        self::assertSame(['command', 'text_regex', 'action', 'action_regex', 'fallback'], $trace);
    }

    public function test_unsupported_capability_is_reported_before_delivery(): void
    {
        $adapter = new FakePlatformAdapter(new PlatformCapabilities(
            actions: true,
            choices: true,
            media: false,
            screenRender: true,
            ack: true,
            attachmentDownload: true,
        ));
        $application = new Application(
            adapter: $adapter,
            router: $router = new \ChatFlow\Routing\Router(),
            container: $container = $this->createContainer(),
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
        );

        $router->onTextPrefix('/media', static function (Context $ctx): void {
            $ctx->reply(View::text('Media')->addMedia(new MediaAttachment('image', 'https://example.com/image.png')));
        });

        $result = $application->handle($this->event('conv-capability', text: '/media'));

        self::assertTrue($result->isError());
        self::assertSame('Current platform does not support media.', $result->getMessage());
        self::assertSame(['reply'], $adapter->deliveries);
        self::assertSame('An internal error occurred. Please try again later.', $adapter->replies[0]->getText());
    }

    public function test_delivery_failure_stops_effect_flush_and_returns_delivery_error(): void
    {
        $adapter = new FakePlatformAdapter(failEffectType: 'render');
        $application = new Application(
            adapter: $adapter,
            router: $router = new \ChatFlow\Routing\Router(),
            container: $container = $this->createContainer(),
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
        );

        $router->onTextPrefix('/effects', static function (Context $ctx): void {
            $ctx->reply('first');
            $ctx->render(View::text('second'));
            $ctx->ack('third');
        });

        $result = $application->handle($this->event('conv-delivery', text: '/effects'));

        self::assertTrue($result->isError());
        self::assertSame('delivery_failed', $result->getMessage());
        self::assertSame(['reply', 'render'], $adapter->deliveries);
        self::assertCount(1, $adapter->replies);
        self::assertCount(0, $adapter->renders);
        self::assertCount(0, $adapter->acks);
    }

    public function test_runtime_observer_receives_core_lifecycle_events(): void
    {
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = new Application(
            adapter: $adapter,
            router: $router = new \ChatFlow\Routing\Router(),
            container: $container = $this->createContainer(),
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
            runtimeObserver: $observer,
        );

        $router->onCommand('start', static function (Context $ctx): void {
            $ctx->reply('hello');
            $ctx->ack('ok');
        });

        $application->handle($this->event('conv-observe', text: '/start'));

        self::assertSame([
            'inbound.received',
            'route.matched',
            'effect.queued',
            'effect.queued',
            'effect.delivered',
            'effect.delivered',
        ], $observer->getNames());
        self::assertSame('conv-observe', $observer->getEvents()[0]->getConversationId());
    }

    public function test_state_manager_expires_sessions_when_ttl_is_configured(): void
    {
        $container = $this->createContainer();
        $sceneRegistry = new SceneRegistry($container);
        $storage = new MemoryStorage();
        $expired = new \ChatFlow\Storage\Session('conv-ttl', time() - 3600);
        $expired->set('name', 'Old');
        $expired->setUpdatedAt(time() - 3600);
        $storage->save('conv-ttl', $expired->toArray());

        $stateManager = new StateManager($sceneRegistry, $storage, sessionTtlSeconds: 60);
        $session = $stateManager->loadSession('conv-ttl');

        self::assertFalse($session->has('name'));
        self::assertFalse($storage->exists('conv-ttl'));
    }

    public function test_scene_lifecycle_validation_and_session_persistence_work_with_fake_adapter(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = $this->createContainer();
        $router = new \ChatFlow\Routing\Router();
        $sceneRegistry = new SceneRegistry($container);
        $sceneRegistry->register(SurveyScene::class);
        $storage = new MemoryStorage();
        $stateManager = new StateManager($sceneRegistry, $storage);

        $application = new Application(
            adapter: $adapter,
            router: $router,
            container: $container,
            errorHandler: new ExceptionRegistry(new NullLogger(), new Config(dirname(__DIR__, 2))),
            validationRegistry: new ValidationRegistry($container),
            stateManager: $stateManager,
        );

        $router->onTextPrefix('/start', static function (Context $ctx): void {
            $ctx->enter(SurveyScene::class);
        });

        $start = $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-3'),
            user: new UserRef(30),
            text: '/start'
        ));

        self::assertTrue($start->isSuccess());
        self::assertSame('Как вас зовут?', $adapter->replies[0]->getText());
        self::assertSame(SurveyScene::class, $stateManager->loadSession('conv-3')->getCurrentScene());

        $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-3'),
            user: new UserRef(30),
            text: 'Alex'
        ));

        $sessionAfterName = $stateManager->loadSession('conv-3');
        self::assertSame('Alex', $sessionAfterName->get('name'));
        self::assertSame('Сколько вам лет?', $adapter->replies[1]->getText());

        $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-3'),
            user: new UserRef(30),
            text: 'oops'
        ));

        self::assertSame('Возраст должен быть числом', $adapter->replies[2]->getText());

        $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-3'),
            user: new UserRef(30),
            text: '30'
        ));

        $sessionAfterAge = $stateManager->loadSession('conv-3');
        self::assertSame(30, $sessionAfterAge->get('age'));
        self::assertSame('Данные сохранены', $adapter->replies[3]->getText());

        $application->handle(new InboundEvent(
            conversation: new ConversationRef('conv-3'),
            user: new UserRef(30),
            actionId: 'scene:onOk'
        ));

        self::assertSame('Всего доброго!', $adapter->replies[4]->getText());
        self::assertNull($stateManager->loadSession('conv-3')->getCurrentScene());
        self::assertCount(1, $adapter->acks);
        self::assertNull($adapter->acks[0]['text']);
        self::assertFalse($adapter->acks[0]['error']);
    }

    public function test_context_enqueue_effect_preserves_order_for_adapter_specific_effects(): void
    {
        $context = new Context(
            new InboundEvent(conversation: new ConversationRef('conv-effects')),
            new FakePlatformAdapter(),
            $this->createContainer(),
        );

        $customEffect = new class () implements OutboundEffectInterface {
            public function getType(): string
            {
                return 'custom.adapter_effect';
            }
        };

        $context->reply('first');
        $context->enqueueEffect($customEffect);
        $context->ack('third');

        self::assertSame(
            ['reply', 'custom.adapter_effect', 'ack'],
            array_map(static fn (OutboundEffectInterface $effect): string => $effect->getType(), $context->getOutboundEffects())
        );
    }

    private function createContainer(): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        return new Container($builder, autowire: true, useAttributes: false);
    }

    private function event(string $conversationId, string $text = '', ?string $actionId = null): InboundEvent
    {
        return new InboundEvent(
            conversation: new ConversationRef($conversationId),
            user: new UserRef(1),
            text: $text,
            actionId: $actionId,
        );
    }
}
