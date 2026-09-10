<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Thrown by platform adapters when an update cannot be represented as an inbound event, for
 * example when it carries no conversation.
 */
class UnsupportedInputException extends BotException {}
