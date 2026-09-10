<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for validation errors.
 *
 * This exception is used when input validation fails, such as invalid
 * user input, failed constraint checks, or other validation-related
 * problems in the application logic.
 */
class ValidationException extends BotException {}
