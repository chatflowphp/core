<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use ChatFlow\Core\ClosureResolver;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use Closure;

/**
 * Fluent definition of the answer a scene is waiting for: validators, text or media shortcuts and
 * the method that receives the accepted answer. Calling handle() stores the definition in the
 * conversation, so it survives until the next update.
 *
 * @phpstan-type InteractionValidator array{rule: string, error: string|null}
 * @phpstan-type InteractionFallback array{type: string, pattern: string|list<string>, handler: string}
 * @phpstan-type InteractionConfig array{validators: list<InteractionValidator>, fallbacks: list<InteractionFallback>, handler: string|null}
 */
final class Interaction
{
    public const TYPE_TEXT = 'text';
    public const TYPE_MEDIA = 'media';

    /**
     * @var list<InteractionValidator>
     */
    private array $validators = [];

    /**
     * @var list<InteractionFallback>
     */
    private array $fallbacks = [];

    public function __construct(private readonly SceneContext $context) {}

    /**
     * Routes an exact text (case-insensitive) or a regular expression to a scene method before
     * validation runs. Useful for "Cancel" style answers.
     *
     * @param string|list<string> $patterns
     * @param string|array{0: object|string, 1: string}|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function onText(string|array $patterns, string|array|Closure $handler): self
    {
        $this->fallbacks[] = [
            'type' => self::TYPE_TEXT,
            'pattern' => $patterns,
            'handler' => ClosureResolver::resolveName($handler),
        ];

        return $this;
    }

    /**
     * Routes an incoming attachment of the given type ("any" for every type) to a scene method.
     *
     * @param string|array{0: object|string, 1: string}|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function onMedia(string $type, string|array|Closure $handler): self
    {
        $this->fallbacks[] = [
            'type' => self::TYPE_MEDIA,
            'pattern' => $type,
            'handler' => ClosureResolver::resolveName($handler),
        ];

        return $this;
    }

    /**
     * Adds a validation rule ("required", "numeric", "regex:/^\d+$/", "email", or a custom alias).
     */
    public function validate(string $rule, ?string $error = null): self
    {
        $this->validators[] = ['rule' => $rule, 'error' => $error];

        return $this;
    }

    /**
     * Stores the interaction. The handler receives the accepted answer on the next update.
     *
     * @param string|array{0: object|string, 1: string}|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function handle(string|array|Closure $handler): void
    {
        $this->context->setInteraction($this->toConfig(ClosureResolver::resolveName($handler)));
    }

    /**
     * @return InteractionConfig
     */
    public function toConfig(?string $handler): array
    {
        return [
            'validators' => $this->validators,
            'fallbacks' => $this->fallbacks,
            'handler' => $handler,
        ];
    }

    /**
     * Validates a stored configuration. Returns null when the stored data does not have the
     * expected shape, so corrupted storage never breaks a conversation.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return InteractionConfig|null
     */
    public static function configFromArray(array $raw): ?array
    {
        $validators = [];
        $fallbacks = [];

        $rawValidators = $raw['validators'] ?? [];
        $rawFallbacks = $raw['fallbacks'] ?? [];
        $handler = $raw['handler'] ?? null;

        if (!\is_array($rawValidators) || !\is_array($rawFallbacks) || ($handler !== null && !\is_string($handler))) {
            return null;
        }

        foreach ($rawValidators as $validator) {
            if (!\is_array($validator) || !isset($validator['rule']) || !\is_string($validator['rule'])) {
                return null;
            }

            $error = $validator['error'] ?? null;
            $validators[] = ['rule' => $validator['rule'], 'error' => \is_string($error) ? $error : null];
        }

        foreach ($rawFallbacks as $fallback) {
            if (
                !\is_array($fallback)
                || !isset($fallback['type'], $fallback['pattern'], $fallback['handler'])
                || !\is_string($fallback['type'])
                || !\is_string($fallback['handler'])
            ) {
                return null;
            }

            $pattern = $fallback['pattern'];

            if (\is_array($pattern)) {
                $patterns = [];

                foreach ($pattern as $candidate) {
                    if (!\is_string($candidate)) {
                        return null;
                    }

                    $patterns[] = $candidate;
                }

                $pattern = $patterns;
            } elseif (!\is_string($pattern)) {
                return null;
            }

            $fallbacks[] = ['type' => $fallback['type'], 'pattern' => $pattern, 'handler' => $fallback['handler']];
        }

        return ['validators' => $validators, 'fallbacks' => $fallbacks, 'handler' => $handler];
    }
}
