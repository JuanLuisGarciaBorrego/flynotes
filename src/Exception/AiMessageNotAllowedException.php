<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * The participant is not the plane's author, so they cannot generate or decide on its AI reinterpretation.
 * Logged as a client error: the API answers it with a 403 (see ApiResource\PaperPlane::exceptionToStatus).
 */
#[WithLogLevel(LogLevel::ERROR)]
final class AiMessageNotAllowedException extends \DomainException
{
}
