<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use ChatFlow\Storage\Session;
use ChatFlow\Support\SerializableValueValidator;
use ChatFlow\View\Action;
use ChatFlow\View\View;
use ChatFlow\View\ViewSerializer;
use PHPUnit\Framework\TestCase;

final class SerializableValueValidatorTest extends TestCase
{
    public function test_backed_enum_is_normalized_to_scalar_value(): void
    {
        self::assertSame(
            ['status' => 'ready'],
            SerializableValueValidator::normalizeMap(['status' => BackedStatusFixture::Ready])
        );
    }

    public function test_unit_enum_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a non-backed enum');

        SerializableValueValidator::normalize(['status' => UnitStatusFixture::Ready]);
    }

    public function test_session_data_is_normalized_before_storage(): void
    {
        $session = new Session('conv-serializable', time());
        $session->set('status', BackedStatusFixture::Ready);

        self::assertSame('ready', $session->toArray()['data']['status']);
    }

    public function test_session_rejects_non_serializable_objects(): void
    {
        $session = new Session('conv-serializable', time());

        $this->expectException(\InvalidArgumentException::class);

        $session->set('object', new \stdClass());
    }

    public function test_view_serializer_outputs_normalized_backed_enum_payloads(): void
    {
        $serialized = (new ViewSerializer())->serialize(
            View::text('Status')->addActionRow(new Action('status:set', 'Set', ['status' => BackedStatusFixture::Ready]))
        );

        self::assertSame('ready', $serialized['actions'][0][0]['payload']['status']);
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
