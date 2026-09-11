<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use ChatFlow\Core\Context;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Maps exceptions to handlers by specificity: the exact class first, then parent classes, then
 * interfaces. A handler registered for Throwable acts as the fallback.
 */
class ExceptionRegistry implements ErrorHandlerInterface
{
    /**
     * @var array<class-string<Throwable>, callable(Throwable, Context|null): void>
     */
    private array $handlers = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(string $exceptionClass, callable $handler): void
    {
        $this->handlers[$exceptionClass] = $handler;
    }

    public function handle(Throwable $e, ?Context $context): void
    {
        $handler = $this->findHandler($e);

        if ($handler !== null) {
            $handler($e, $context);

            return;
        }

        $this->defaultHandler($e, $context);
    }

    /**
     * @return (callable(Throwable, Context|null): void)|null
     */
    private function findHandler(Throwable $e): ?callable
    {
        foreach ($this->candidates($e) as $class) {
            if (isset($this->handlers[$class])) {
                return $this->handlers[$class];
            }
        }

        return null;
    }

    /**
     * @return list<class-string>
     */
    private function candidates(Throwable $e): array
    {
        return [
            $e::class,
            ...array_values(class_parents($e)),
            ...array_values(class_implements($e)),
        ];
    }

    private function defaultHandler(Throwable $e, ?Context $context): void
    {
        $data = $context === null ? [] : [
            'conversation_id' => $context->getConversationId(),
            'user_id' => $context->getUserId(),
            'action_id' => $context->getActionId(),
            'scene' => $context->hasConversation() ? $context->getCurrentScene() : null,
        ];

        $this->logger->error('Unhandled exception', $data + [
            'exception' => $e::class,
            'exception_message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        if ($context === null) {
            return;
        }

        if ($this->debug) {
            $message = \sprintf('[%s] %s at %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        } elseif ($e instanceof UserFriendlyException) {
            $message = $e->getMessage();
        } else {
            $message = 'An internal error occurred. Please try again later.';
        }

        try {
            // A failed button press is acknowledged as an alert so the client stops waiting;
            // anything else gets a message.
            if ($context->isAction()) {
                $context->ack($message, true);
            } else {
                $context->reply($message);
            }
        } catch (Throwable) {
            // Replying is best-effort: the platform may not accept messages in this context.
        }
    }
}
