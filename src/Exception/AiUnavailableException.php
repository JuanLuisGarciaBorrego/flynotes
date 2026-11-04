<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * No model call can be made: AI_ENABLED is off, or the provider failed (and that was already logged, with its details).
 * Nothing technical travels with it. The API answers it with a 503 (see `exceptionToStatus` in ApiResource).
 */
#[WithLogLevel(LogLevel::WARNING)]
final class AiUnavailableException extends \RuntimeException
{
    public const string MESSAGE = 'AI is temporarily unavailable. Paper planes are still flying!';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
