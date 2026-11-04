<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * The participant already stamped this plane.
 * Logged as a client error: the API answers it with a 409 (see PaperPlaneStamp::exceptionToStatus).
 */
#[WithLogLevel(LogLevel::ERROR)]
final class AlreadyStampedException extends \DomainException
{
}
