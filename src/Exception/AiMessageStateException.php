<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * The AI reinterpretation is not in a state that allows the operation: already decided, no generations left,
 * nothing pending, or another generation finished first. Logged as a client error: the API answers it with a
 * 409 (see ApiResource\PaperPlane::exceptionToStatus).
 */
#[WithLogLevel(LogLevel::ERROR)]
final class AiMessageStateException extends \DomainException
{
}
