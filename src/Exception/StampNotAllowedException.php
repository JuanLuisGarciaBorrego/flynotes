<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * The participant cannot stamp this plane (it is their own plane, or they have no country).
 * Logged as a client error: the API answers it with a 403 (see PaperPlaneStamp::exceptionToStatus).
 */
#[WithLogLevel(LogLevel::ERROR)]
final class StampNotAllowedException extends \DomainException
{
}
