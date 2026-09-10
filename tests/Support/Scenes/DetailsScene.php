<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;

final class DetailsScene extends RecordingScene
{
    public function onBack(Context $ctx): void
    {
        $ctx->back();
    }
}
