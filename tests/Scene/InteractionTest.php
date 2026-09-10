<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use ChatFlow\Exception\LogicException;
use ChatFlow\Scene\Interaction;
use ChatFlow\Scene\SceneContext;
use PHPUnit\Framework\TestCase;

final class InteractionTest extends TestCase
{
    public function testConfigIsBuiltFromFluentCalls(): void
    {
        $interaction = new Interaction(new SceneContext());
        $config = $interaction
            ->validate('numeric')
            ->validate('regex:/^\d{2}$/', 'Two digits')
            ->onText(['cancel', 'stop'], [self::class, 'onCancel'])
            ->onMedia('photo', 'onPhoto')
            ->toConfig('save');

        self::assertSame([
            'validators' => [
                ['rule' => 'numeric', 'error' => null],
                ['rule' => 'regex:/^\d{2}$/', 'error' => 'Two digits'],
            ],
            'fallbacks' => [
                ['type' => 'text', 'pattern' => ['cancel', 'stop'], 'handler' => 'onCancel'],
                ['type' => 'media', 'pattern' => 'photo', 'handler' => 'onPhoto'],
            ],
            'handler' => 'save',
        ], $config);
    }

    public function testClosuresAreRejectedAsHandlers(): void
    {
        $this->expectException(LogicException::class);

        (new Interaction(new SceneContext()))->handle(static fn(): null => null);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfigs')]
    public function testInvalidStoredConfigsAreRejected(array $raw): void
    {
        self::assertNull(Interaction::configFromArray($raw));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'validators not a list' => [['validators' => 'x', 'fallbacks' => [], 'handler' => null]];
        yield 'validator without rule' => [['validators' => [['error' => 'x']], 'fallbacks' => [], 'handler' => null]];
        yield 'fallback without handler' => [['validators' => [], 'fallbacks' => [['type' => 'text', 'pattern' => 'a']], 'handler' => null]];
        yield 'fallback with non-string patterns' => [['validators' => [], 'fallbacks' => [['type' => 'text', 'pattern' => [1], 'handler' => 'h']], 'handler' => null]];
        yield 'handler not a string' => [['validators' => [], 'fallbacks' => [], 'handler' => 5]];
    }

    public function testMinimalStoredConfigIsAccepted(): void
    {
        self::assertSame(
            ['validators' => [], 'fallbacks' => [], 'handler' => null],
            Interaction::configFromArray([]),
        );
    }

    public static function onCancel(): void {}
}
