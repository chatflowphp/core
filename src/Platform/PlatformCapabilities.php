<?php

declare(strict_types=1);

namespace ChatFlow\Platform;

final class PlatformCapabilities
{
    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        private readonly bool $actions,
        private readonly bool $choices,
        private readonly bool $media,
        private readonly bool $screenRender,
        private readonly bool $ack,
        private readonly bool $attachmentDownload,
        private readonly array $extensions = [],
    ) {
    }

    public function supportsActions(): bool
    {
        return $this->actions;
    }

    public function supportsChoices(): bool
    {
        return $this->choices;
    }

    public function supportsMedia(): bool
    {
        return $this->media;
    }

    public function supportsScreenRender(): bool
    {
        return $this->screenRender;
    }

    public function supportsAck(): bool
    {
        return $this->ack;
    }

    public function supportsAttachmentDownload(): bool
    {
        return $this->attachmentDownload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * @return array{
     *     actions: bool,
     *     choices: bool,
     *     media: bool,
     *     screen_render: bool,
     *     ack: bool,
     *     attachment_download: bool,
     *     extensions: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'actions' => $this->actions,
            'choices' => $this->choices,
            'media' => $this->media,
            'screen_render' => $this->screenRender,
            'ack' => $this->ack,
            'attachment_download' => $this->attachmentDownload,
            'extensions' => $this->extensions,
        ];
    }
}
