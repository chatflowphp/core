<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Scene runtime errors: invalid scene classes, hooks called outside of a tick, missing handlers.
 */
class SceneException extends BotException {}
