<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The mission of an AgentMissionToolDecision must have a research tool waiting for a human. The message tells why
 * it does not (AgentMission::getToolDecisionConflict()).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MissionAwaitsToolApproval extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
