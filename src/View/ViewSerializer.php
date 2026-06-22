<?php

declare(strict_types=1);

namespace ChatFlow\View;

final class ViewSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(View $view): array
    {
        return [
            'text' => $view->getText(),
            'actions' => array_map(
                fn (array $row): array => array_map(fn (Action $action): array => $this->serializeAction($action), $row),
                $view->getActions(),
            ),
            'choices' => array_map(
                fn (array $row): array => array_map(fn (Choice $choice): array => $this->serializeChoice($choice), $row),
                $view->getChoices(),
            ),
            'media' => array_map(fn (MediaAttachment $attachment): array => $this->serializeMedia($attachment), $view->getMedia()),
            'meta' => $view->getMeta(),
        ];
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
