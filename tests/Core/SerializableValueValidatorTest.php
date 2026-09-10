<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use ChatFlow\Scene\SceneContext;
use ChatFlow\Support\SerializableValueValidator;
use ChatFlow\View\Action;
use ChatFlow\View\View;
use ChatFlow\View\ViewSerializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SerializableValueValidatorTest extends TestCase
{
    public function testBackedEnumIsNormalizedToScalarValue(): void
    {
        self::assertSame(
            ['status' => 'ready'],
            SerializableValueValidator::normalizeMap(['status' => BackedStatusFixture::Ready]),
        );
    }

    public function testUnitEnumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a non-backed enum');

        SerializableValueValidator::normalize(['status' => UnitStatusFixture::Ready]);
    }

    public function testObjectsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload.object must contain only scalar');

        SerializableValueValidator::normalize(['object' => new \stdClass()], 'payload');
    }

    public function testMapsRequireStringKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SerializableValueValidator::normalizeMap([1 => 'one']);
    }

    public function testSessionDataIsNormalizedOnWrite(): void
    {
        $session = new SceneContext();
        $session->set('status', BackedStatusFixture::Ready);
        $session->set('cart', [1 => 2, 3 => 1]);

        self::assertSame(['status' => 'ready', 'cart' => [1 => 2, 3 => 1]], $session->all());
    }

    public function testViewSerializerOutputsNormalizedBackedEnumPayloads(): void
    {
        $serialized = (new ViewSerializer())->serialize(
            View::text('Status')->addActionRow(new Action('status:set', 'Set', ['status' => BackedStatusFixture::Ready])),
        );

        self::assertSame(['status' => 'ready'], $serialized['actions'][0][0]['payload']);
    }
}

enum BackedStatusFixture: string
{
    case Ready = 'ready';
}

enum UnitStatusFixture
{
    case Ready;
}
