<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for finite state machine (FSM) related errors.
 *
 * This exception is used when there are issues with the state machine
 * operations, such as invalid state transitions, scene loading failures,
 * or other FSM-related problems.
 */
class FSMException extends BotException
{
}
