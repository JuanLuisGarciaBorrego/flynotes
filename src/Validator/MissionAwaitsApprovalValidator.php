<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\AgentMissionDecision;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Early check only: two simultaneous requests can both pass it. The guarantee that a mission is decided once
 * is the row lock taken by AgentMissionManager::decide().
 */
final class MissionAwaitsApprovalValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MissionAwaitsApproval) {
            throw new UnexpectedTypeException($constraint, MissionAwaitsApproval::class);
        }

        if (!$value instanceof AgentMissionDecision) {
            throw new UnexpectedValueException($value, AgentMissionDecision::class);
        }

        $conflict = $value->mission->getDecisionConflict();
        if (null !== $conflict) {
            $this->context->buildViolation($conflict)->addViolation();
        }
    }
}
