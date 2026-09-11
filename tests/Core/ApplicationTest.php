<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use ChatFlow\Container\Container;
use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Platform\PlatformCapabilities;
use ChatFlow\Routing\Route;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\MenuScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Tests\Support\TraceRuntimeObserver;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ApplicationTest extends TestCase
{
    private const INTERNAL_ERROR = 'An internal error occurred. Please try again later.';

    public function testTextRouteRunsThroughMiddlewareAndDeliversEffects(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $trace = new HookLog();

        $application->middleware([
            new class ($trace) implements MiddlewareInterface {
                public function __construct(private readonly HookLog $trace) {}

                public function process(Context $ctx, callable $next): mixed
                {
                    $this->trace->add('global-before');
                    $ctx->set('from_middleware', true);
                    $result = $next($ctx);
                    $this->trace->add('global-after');

                    return $result;
                }
            },
        ]);

        $application->onTextPrefix('/hello', static function (Context $ctx) use ($trace): void {
            $trace->add($ctx->get('from_middleware') === true ? 'handler' : 'handler-missing');
            $ctx->reply(View::text('Hello route'));
            $ctx->render(View::text('Rendered route'));
            $ctx->ack('route-ack');
        });

        $result = $application->handle(TestApp::event('conv-1', '/hello'));

        self::assertTrue($result->isSuccess());
        self::assertSame('route_processed', $result->getMessage());
        self::assertSame(['global-before', 'handler', 'global-after'], $trace->all());
        self::assertCount(1, $adapter->replies);
        self::assertSame('Hello route', $adapter->replies[0]->getText());
        self::assertCount(1, $adapter->renders);
        self::assertSame('Rendered route', $adapter->renders[0]->getText());
        self::assertSame([['text' => 'route-ack', 'error' => false]], $adapter->acks);
    }

    public function testRouterSupportsEveryRouteTypeIncludingGroupCommands(): void
    {
        $application = TestApp::create();
        $trace = [];

        $application->onCommand('start', static function () use (&$trace): void {
            $trace[] = 'command';
        });
        $application->onTextRegex('/^ticket:\d+$/', static function () use (&$trace): void {
            $trace[] = 'text_regex';
        });
        $application->onTextPrefix('find ', static function () use (&$trace): void {
            $trace[] = 'text_prefix';
        });
        $application->onAction('support:open', static function () use (&$trace): void {
            $trace[] = 'action';
        });
        $application->onActionPrefix('menu:', static function () use (&$trace): void {
            $trace[] = 'action_prefix';
        });
        $application->onActionRegex('/^support:/', static function () use (&$trace): void {
            $trace[] = 'action_regex';
        });
        $application->fallback(static function () use (&$trace): void {
            $trace[] = 'fallback';
        });

        $application->handle(TestApp::event('c', '/start now'));
        $application->handle(TestApp::event('c', '/start@my_bot'));
        $application->handle(TestApp::event('c', '/starting'));
        $application->handle(TestApp::event('c', 'ticket:123'));
        $application->handle(TestApp::event('c', 'find me'));
        $application->handle(TestApp::event('c', actionId: 'support:open'));
        $application->handle(TestApp::event('c', actionId: 'menu:open'));
        $application->handle(TestApp::event('c', actionId: 'support:refresh'));
        $noMatch = $application->handle(TestApp::event('c', 'unknown'));

        self::assertSame(
            ['command', 'command', 'fallback', 'text_regex', 'text_prefix', 'action', 'action_prefix', 'action_regex', 'fallback'],
            $trace,
        );
        self::assertTrue($noMatch->isSuccess());
    }

    public function testNoMatchingRouteReturnsNoMatchWithoutPersistingAnything(): void
    {
        $storage = new MemoryStorage();
        $application = TestApp::create(storage: $storage);
        $application->onCommand('start', static fn(): null => null);

        $result = $application->handle(TestApp::event('stranger', 'hello?'));

        self::assertTrue($result->isNoMatch());
        self::assertSame([], $storage->keys());
    }

    public function testConversationsArePersistedOnceTheyStoreData(): void
    {
        $storage = new MemoryStorage();
        $application = TestApp::create(storage: $storage);
        $application->onCommand('remember', static function (Context $ctx): void {
            $ctx->session()->set('name', 'Alex');
        });
        $application->onCommand('recall', static function (Context $ctx): void {
            $ctx->reply($ctx->session()->getString('name', 'nobody'));
        });

        $application->handle(TestApp::event('conv-2', '/remember'));

        self::assertSame(['conv-2'], $storage->keys());

        $adapter = new FakePlatformAdapter();
        $again = TestApp::create($adapter, $storage);
        $again->onCommand('recall', static function (Context $ctx): void {
            $ctx->reply($ctx->session()->getString('name', 'nobody'));
        });
        $again->handle(TestApp::event('conv-2', '/recall'));

        self::assertSame('Alex', $adapter->replies[0]->getText());
    }

    public function testUnsupportedCapabilityIsReportedAndOnlyTheErrorHandlerReplies(): void
    {
        $adapter = new FakePlatformAdapter(new PlatformCapabilities(
            actions: true,
            choices: true,
            media: false,
            screenRender: true,
            ack: true,
            attachmentDownload: true,
        ));
        $application = TestApp::create($adapter);
        $application->onTextPrefix('/media', static function (Context $ctx): void {
            $ctx->reply('before');
            $ctx->reply(View::text('Media')->addMedia(new MediaAttachment('image', 'https://example.com/image.png')));
        });

        $result = $application->handle(TestApp::event('conv-capability', '/media'));

        self::assertTrue($result->isError());
        self::assertSame('Current platform does not support media.', $result->getMessage());
        self::assertSame(['reply'], $adapter->deliveries);
        self::assertSame(self::INTERNAL_ERROR, $adapter->replies[0]->getText());
    }

    public function testDeliveryFailureStopsTheFlushAndReturnsADeliveryError(): void
    {
        $adapter = new FakePlatformAdapter(failEffectType: 'render');
        $application = TestApp::create($adapter);
        $application->onTextPrefix('/effects', static function (Context $ctx): void {
            $ctx->reply('first');
            $ctx->render(View::text('second'));
            $ctx->ack('third');
        });

        $result = $application->handle(TestApp::event('conv-delivery', '/effects'));

        self::assertTrue($result->isError());
        self::assertSame('delivery_failed', $result->getMessage());
        self::assertSame(['reply', 'render'], $adapter->deliveries);
        self::assertCount(1, $adapter->replies);
        self::assertCount(0, $adapter->renders);
        self::assertCount(0, $adapter->acks);
    }

    public function testRuntimeObserverReceivesLifecycleEvents(): void
    {
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create(observer: $observer);
        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply('hello');
            $ctx->ack('ok');
        });

        $application->handle(TestApp::event('conv-observe', '/start'));

        self::assertSame([
            'inbound.received',
            'route.matched',
            'effect.queued',
            'effect.queued',
            'effect.delivered',
            'effect.delivered',
        ], $observer->getNames());
        self::assertSame('conv-observe', $observer->getEvents()[0]->getConversationId());
        self::assertSame(['route_type' => 'command', 'pattern' => 'start'], $observer->getEvents()[1]->getData());
    }

    public function testEffectsQueuedBeforeAFailureAreDroppedAndTheErrorHandlerReplies(): void
    {
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create($adapter, observer: $observer);
        $application->onCommand('boom', static function (Context $ctx): void {
            $ctx->reply('half done');

            throw new RuntimeException('exploded');
        });

        $result = $application->handle(TestApp::event('conv-fail', '/boom'));

        self::assertTrue($result->isError());
        self::assertSame('exploded', $result->getMessage());
        self::assertSame([self::INTERNAL_ERROR], array_map(static fn(View $view): string => $view->getText(), $adapter->replies));
        self::assertContains('handler.failed', $observer->getNames());
        self::assertSame([['conversation' => 'conv-fail', 'status' => 'error']], $adapter->afterHandle, 'the adapter hook runs after failures too');
    }

    public function testCustomErrorHandlersAreUsed(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $application->onException(RuntimeException::class, static function (Throwable $exception, ?Context $ctx): void {
            $ctx?->reply('runtime: ' . $exception->getMessage());
        });
        $application->setErrorHandler(static function (Throwable $exception, Context $ctx): void {
            $ctx->reply('fallback: ' . $exception->getMessage());
        });
        $application->onCommand('runtime', static function (): void {
            throw new RuntimeException('r');
        });
        $application->onCommand('logic', static function (): void {
            throw new \LogicException('l');
        });

        $application->handle(TestApp::event('conv-err', '/runtime'));
        $application->handle(TestApp::event('conv-err', '/logic'));

        self::assertSame(['runtime: r', 'fallback: l'], array_map(static fn(View $view): string => $view->getText(), $adapter->replies));
    }

    public function testMiddlewareCanShortCircuitWithAResult(): void
    {
        $storage = new MemoryStorage();
        $application = TestApp::create(storage: $storage);
        $called = false;
        $application->middleware([
            new class implements MiddlewareInterface {
                public function process(Context $ctx, callable $next): mixed
                {
                    return Result::error('blocked');
                }
            },
        ]);
        $application->onCommand('start', static function (Context $ctx) use (&$called): void {
            $called = true;
            $ctx->session()->set('x', 1);
        });

        $result = $application->handle(TestApp::event('conv-block', '/start'));

        self::assertFalse($called);
        self::assertTrue($result->isError());
        self::assertSame('blocked', $result->getMessage());
        self::assertSame([], $storage->keys());
    }

    public function testRequestScopedBindingsAreFlushedAfterHandling(): void
    {
        $container = new Container();
        $application = TestApp::create(container: $container);
        $seen = null;
        $application->onCommand('start', static function (Context $ctx, Container $c) use (&$seen): void {
            $seen = $c->get(Context::class) === $ctx;
            $c->scoped('request.marker', true);
        });

        $application->handle(TestApp::event('conv-scope', '/start'));

        self::assertTrue($seen);
        self::assertFalse($container->has('request.marker'));
    }

    public function testCustomRouteOverridesTheRouter(): void
    {
        $adapter = new FakePlatformAdapter();
        $log = new HookLog();
        $container = new Container();
        $container->set(HookLog::class, $log);
        $application = TestApp::create($adapter, container: $container);
        $application->registerScene(MenuScene::class);
        $application->onCommand('menu', static function (Context $ctx): void {
            $ctx->enter(MenuScene::class);
        });
        $media = Route::custom('media', static function (Context $ctx): void {
            $ctx->reply('media handler');
        });

        $application->handle(TestApp::event('conv-custom', 'anything'), $media);
        self::assertSame('media handler', $adapter->replies[0]->getText());

        $application->handle(TestApp::event('conv-custom', '/menu'));
        $application->handle(TestApp::event('conv-custom', 'photo'), $media);
        self::assertContains('Menu:handle:photo', $log->all());

        $application->handle(TestApp::event('conv-custom', 'photo'), $media->global());
        $last = end($adapter->replies);
        self::assertInstanceOf(View::class, $last);
        self::assertSame('media handler', $last->getText());
    }

    public function testApplicationImplementsTheFlowRuntimeContract(): void
    {
        $adapter = new FakePlatformAdapter();
        $log = new HookLog();
        $container = new Container();
        $container->set(HookLog::class, $log);
        $application = TestApp::create($adapter, container: $container);

        $flow = new class implements FlowInterface {
            public function register(FlowRuntimeInterface $runtime): void
            {
                $runtime->registerScene(MenuScene::class, 'Menu');
                $runtime->onCommand('start', static function (Context $ctx): void {
                    $ctx->enter(MenuScene::class);
                });
                $runtime->fallback(static function (Context $ctx): void {
                    $ctx->reply('fallback');
                });
            }
        };
        $flow->register($application);

        self::assertInstanceOf(FlowRuntimeInterface::class, $application);
        self::assertSame('Menu', $application->getScenes()->getLabel(MenuScene::class));

        $application->handle(TestApp::event('conv-flow', '/start'));

        self::assertSame('Menu screen', $adapter->replies[0]->getText());
        self::assertSame(MenuScene::class, $application->getConversations()->resume('conv-flow')->getCurrentScene());
    }

    public function testContextEnqueueEffectPreservesOrderForAdapterSpecificEffects(): void
    {
        $context = new Context(TestApp::event('conv-effects'), new FakePlatformAdapter(), new Container());
        $custom = new class implements OutboundEffectInterface {
            public function getType(): string
            {
                return 'custom.adapter_effect';
            }
        };

        $context->reply('first');
        $context->enqueueEffect($custom);
        $context->ack('third');

        self::assertSame(
            ['reply', 'custom.adapter_effect', 'ack'],
            array_map(static fn(OutboundEffectInterface $effect): string => $effect->getType(), $context->getOutboundEffects()),
        );
    }

    public function testSessionAccessOutsideOfHandlingIsRejected(): void
    {
        $context = new Context(TestApp::event('conv-x'), new FakePlatformAdapter(), new Container());

        self::assertFalse($context->hasConversation());
        $this->expectException(\ChatFlow\Exception\SceneException::class);

        $context->session();
    }

    public function testApplicationWorksWithoutExplicitDependencies(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = new Application($adapter, new Container());
        $application->onCommand('ping', static function (Context $ctx): void {
            $ctx->reply('pong');
        });

        self::assertTrue($application->handle(TestApp::event('conv-min', '/ping'))->isSuccess());
        self::assertSame('pong', $adapter->replies[0]->getText());
    }

    public function testCommandHandlersReadTheDeepLinkArgument(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);

        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply(View::text('ref=' . $ctx->getCommandArgument()));
        });

        $application->handle(TestApp::event('conv-1', '/start@my_bot ref_abc123'));

        self::assertCount(1, $adapter->replies);
        self::assertSame('ref=ref_abc123', $adapter->replies[0]->getText());
    }

    public function testTheMatchedRouteIsAvailableOnTheContext(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $route = null;
        $argument = null;

        $application->onTextPrefix('/hello', static function (Context $ctx) use (&$route, &$argument): void {
            $route = $ctx->getRoute();
            $argument = $ctx->getCommandArgument();
            $ctx->reply(View::text('ok'));
        });

        $application->handle(TestApp::event('conv-1', '/hello there'));

        self::assertInstanceOf(Route::class, $route);
        self::assertSame(Route::TEXT_PREFIX, $route->getType());
        self::assertSame('', $argument, 'Only command routes have an argument.');
    }

    public function testOutsideRequestEntryCanCarryPlatformFactsInTheConversationReference(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $meta = null;
        $conversationId = null;

        $result = $application->run(
            new ConversationRef('-100500:222', 'telegram', ['chat' => ['id' => -100500]]),
            static function (Context $ctx) use (&$meta, &$conversationId): void {
                $meta = $ctx->getConversation()->getMeta();
                $conversationId = $ctx->getConversationId();
                $ctx->reply(View::text('scheduled'));
            },
        );

        self::assertTrue($result->isSuccess());
        self::assertSame('-100500:222', $conversationId);
        self::assertSame(['chat' => ['id' => -100500]], $meta, 'The adapter facts survive into the handler.');
        self::assertCount(1, $adapter->replies);
    }
}
