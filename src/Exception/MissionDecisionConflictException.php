<?php

declare(strict_types=1);

namespace App\Exception;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

/**
 * Another human decided first, after this request had passed validation. The message tells the real outcome.
 * The API answers it with a 422, like MissionAwaitsApproval (see ApiResource\AgentMission::exceptionToStatus).
 */
#[WithLogLevel(LogLevel::INFO)]
final class MissionDecisionConflictException extends \DomainException
{
}
