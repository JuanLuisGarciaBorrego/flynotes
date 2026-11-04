<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The mission of an AgentMissionDecision must admit a decision. The message tells why it does not
 * (AgentMission::getDecisionConflict()).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MissionAwaitsApproval extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
