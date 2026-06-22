<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use ChatFlow\Platform\PlatformCapabilities;
use PHPUnit\Framework\TestCase;

final class PlatformCapabilitiesTest extends TestCase
{
    public function test_capabilities_are_explicit_and_serializable(): void
    {
        $capabilities = new PlatformCapabilities(
            actions: true,
            choices: false,
            media: true,
            screenRender: true,
            ack: false,
            attachmentDownload: true,
            extensions: ['platform' => 'test'],
        );

        self::assertTrue($capabilities->supportsActions());
        self::assertFalse($capabilities->supportsChoices());
        self::assertTrue($capabilities->supportsMedia());
        self::assertTrue($capabilities->supportsScreenRender());
        self::assertFalse($capabilities->supportsAck());
        self::assertTrue($capabilities->supportsAttachmentDownload());
        self::assertSame(['platform' => 'test'], $capabilities->getExtensions());
        self::assertSame([
            'actions' => true,
            'choices' => false,
            'media' => true,
            'screen_render' => true,
            'ack' => false,
            'attachment_download' => true,
            'extensions' => ['platform' => 'test'],
        ], $capabilities->toArray());
    }
}
