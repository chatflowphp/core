<?php

declare(strict_types=1);

namespace ChatFlow\View;

/**
 * Converts views into plain arrays, for example for logging, snapshots in tests or transports.
 *
 * @phpstan-type SerializedAction array{id: string, label: string, payload: mixed, url: string|null, meta: array<string, mixed>}
 * @phpstan-type SerializedChoice array{label: string, value: string, meta: array<string, mixed>}
 * @phpstan-type SerializedMedia array{type: string, source: string, meta: array<string, mixed>}
 * @phpstan-type SerializedView array{text: string, actions: list<list<SerializedAction>>, choices: list<list<SerializedChoice>>, media: list<SerializedMedia>, meta: array<string, mixed>}
 */
final class ViewSerializer
{
    /**
     * @return SerializedView
     */
    public function serialize(View $view): array
    {
        return [
            'text' => $view->getText(),
            'actions' => array_map(
                fn(array $row): array => array_map(fn(Action $action): array => $this->serializeAction($action), $row),
                $view->getActions(),
            ),
            'choices' => array_map(
                fn(array $row): array => array_map(fn(Choice $choice): array => $this->serializeChoice($choice), $row),
                $view->getChoices(),
            ),
            'media' => array_map(fn(MediaAttachment $attachment): array => $this->serializeMedia($attachment), $view->getMedia()),
            'meta' => $view->getMeta(),
        ];
    }

    /**
     * @return SerializedAction
     */
    public function serializeAction(Action $action): array
    {
        return [
            'id' => $action->getId(),
            'label' => $action->getLabel(),
            'payload' => $action->getPayload(),
            'url' => $action->getUrl(),
            'meta' => $action->getMeta(),
        ];
    }

    /**
     * @return SerializedChoice
     */
    public function serializeChoice(Choice $choice): array
    {
        return [
            'label' => $choice->getLabel(),
            'value' => $choice->getValue(),
            'meta' => $choice->getMeta(),
        ];
    }

    /**
     * @return SerializedMedia
     */
    public function serializeMedia(MediaAttachment $attachment): array
    {
        return [
            'type' => $attachment->getType(),
            'source' => $attachment->getSource(),
            'meta' => $attachment->getMeta(),
        ];
    }
}
