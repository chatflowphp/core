<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Fixtures;

use ChatFlow\Core\Context;
use ChatFlow\FSM\BaseScene;
use ChatFlow\View\View;

class SurveyScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask('Как вас зовут?')
            ->validate('required', 'Имя не должно быть пустым')
            ->handle('handleName');
    }

    public function handleName(Context $ctx): void
    {
        $ctx->session()->set('name', $ctx->getText());

        $this->ask('Сколько вам лет?')
            ->validate('numeric', 'Возраст должен быть числом')
            ->handle('handleAge');
    }

    public function handleAge(Context $ctx): void
    {
        $ctx->session()->set('age', (int) $ctx->getText());

        $ctx->reply(
            View::text('Данные сохранены')
                ->addActionRow($this->sceneAction('ОК', 'onOk'))
        );
    }

    public function onOk(Context $ctx): void
    {
        $ctx->ack();
        $ctx->reply('Всего доброго!');
        $this->leave();
    }
}
