<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Only one Sky Pulse mission can be active at a time. See MissionAvailableToStartValidator for what it does not guarantee.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MissionAvailableToStart extends Constraint
{
    public const string MISSION_ALREADY_ACTIVE_ERROR = '6d1f4a57-3c0e-4b8e-9a52-7f0c1e2d9b34';

    public string $message = 'A Sky Pulse mission is already underway. Check back in a few minutes!';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
