<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

final class View
{
    /**
     * @param list<list<Action>>    $actions
     * @param list<list<Choice>>    $choices
     * @param list<MediaAttachment> $media
     * @param array<string, mixed>  $meta
     */
    public function __construct(
        private readonly string $text = '',
        private readonly array $actions = [],
        private readonly array $choices = [],
        private readonly array $media = [],
        private readonly array $meta = [],
    ) {
        SerializableValueValidator::assertSerializable($this->meta, 'view meta');
    }

    public static function text(string $text): self
    {
        return new self($text);
    }

    public function withText(string $text): self
    {
        return new self($text, $this->actions, $this->choices, $this->media, $this->meta);
    }

    /**
     * @param list<list<Action>> $actions
     */
    public function withActions(array $actions): self
    {
        return new self($this->text, $actions, $this->choices, $this->media, $this->meta);
    }

    public function addActionRow(Action ...$actions): self
    {
        return new self(
            $this->text,
            [...$this->actions, $actions],
            $this->choices,
            $this->media,
            $this->meta
        );
    }

    /**
     * @param list<list<Choice>> $choices
     */
    public function withChoices(array $choices): self
    {
        return new self($this->text, $this->actions, $choices, $this->media, $this->meta);
    }

    public function addChoiceRow(Choice ...$choices): self
    {
        return new self(
            $this->text,
            $this->actions,
            [...$this->choices, $choices],
            $this->media,
            $this->meta
        );
    }

    /**
     * @param list<MediaAttachment> $media
     */
    public function withMedia(array $media): self
    {
        return new self($this->text, $this->actions, $this->choices, $media, $this->meta);
    }

    public function addMedia(MediaAttachment $attachment): self
    {
        return new self(
            $this->text,
            $this->actions,
            $this->choices,
            [...$this->media, $attachment],
            $this->meta
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->text, $this->actions, $this->choices, $this->media, $meta);
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return list<list<Action>>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * @return list<list<Choice>>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /**
     * @return list<MediaAttachment>
     */
    public function getMedia(): array
    {
        return $this->media;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return SerializableValueValidator::normalizeMap($this->meta, 'view meta');
    }
}
