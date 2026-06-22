<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use ChatFlow\Config\ConfigInterface;
use ChatFlow\Core\Context;
use Psr\Log\LoggerInterface;
use Throwable;

class ExceptionRegistry implements ErrorHandlerInterface
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigInterface $config,
    ) {
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

    private function defaultHandler(Throwable $e, ?Context $context): void
    {
        $this->logWithContext('error', 'Exception occurred', $e, $context);

        if ($context !== null) {
            $this->sendErrorMessage($e, $context);
        }
    }

    private function findHandler(Throwable $e): ?callable
    {
        $exceptionClass = get_class($e);
        if (isset($this->handlers[$exceptionClass])) {
            return $this->handlers[$exceptionClass];
        }

        foreach ($this->handlers as $class => $handler) {
            if ($e instanceof $class) {
                return $handler;
            }
        }

        return null;
    }

    private function sendErrorMessage(Throwable $e, Context $context): void
    {
        $debug = $this->config->getBool('APP_DEBUG', false);

        if ($debug) {
            $message = sprintf(
                '[%s] %s at %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        } elseif ($e instanceof UserFriendlyException) {
            $message = $e->getMessage();
        } else {
            $message = 'An internal error occurred. Please try again later.';
        }

        try {
            $context->reply($message);
        } catch (Throwable) {
        }
    }

    private function logWithContext(string $level, string $message, Throwable $e, ?Context $context): void
    {
        $contextData = $this->buildContextData($context);
        $contextData['exception'] = get_class($e);
        $contextData['exception_message'] = $e->getMessage();
        $contextData['file'] = $e->getFile();
        $contextData['line'] = $e->getLine();

        $this->logger->log($level, $message, $contextData);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContextData(?Context $context): array
    {
        if ($context === null) {
            return [];
        }

        $data = [
            'conversation_id' => $context->getConversationId(),
            'user_id' => $context->getUserId(),
            'action_id' => $context->getActionId(),
        ];

        $session = $context->getSession();
        if ($session !== null) {
            $data['scene'] = $session->getCurrentScene();
        }

        return $data;
    }
}
