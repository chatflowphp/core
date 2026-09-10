<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Throwable;

/**
 * Marker interface for exceptions with messages safe for end-users.
 */
interface UserFriendlyException extends Throwable {}
