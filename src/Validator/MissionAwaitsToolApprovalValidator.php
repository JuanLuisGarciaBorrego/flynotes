<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\AgentMissionToolDecision;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Early check only: two simultaneous requests can both pass it. The guarantee that a tool is decided once
 * is the row lock taken by AgentMissionManager::decideTool().
 */
final class MissionAwaitsToolApprovalValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MissionAwaitsToolApproval) {
            throw new UnexpectedTypeException($constraint, MissionAwaitsToolApproval::class);
        }

        if (!$value instanceof AgentMissionToolDecision) {
            throw new UnexpectedValueException($value, AgentMissionToolDecision::class);
        }

        $conflict = $value->mission->getToolDecisionConflict();
        if (null !== $conflict) {
            $this->context->buildViolation($conflict)->addViolation();
        }
    }
}
