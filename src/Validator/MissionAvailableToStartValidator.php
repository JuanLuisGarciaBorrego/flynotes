<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\AgentMissionRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Read-only availability check, not a uniqueness guarantee: two simultaneous requests can both pass it
 * before either mission is stored.
 */
final class MissionAvailableToStartValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AgentMissionRepository $missions,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MissionAvailableToStart) {
            throw new UnexpectedTypeException($constraint, MissionAvailableToStart::class);
        }

        if (!$this->missions->hasActiveMission()) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(MissionAvailableToStart::MISSION_ALREADY_ACTIVE_ERROR)
            ->addViolation();
    }
}
