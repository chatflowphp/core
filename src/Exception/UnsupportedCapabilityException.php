<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use RuntimeException;

final class UnsupportedCapabilityException extends RuntimeException implements ChatFlowException
{
}
