<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\AgentMission;
use App\Validator\MissionAwaitsApproval;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of POST /agent_missions/{uuid}/decision. The mission comes from the URL (AgentMissionDecisionProvider);
 * only `accepted` is read from the body.
 */
#[MissionAwaitsApproval]
final class AgentMissionDecision
{
    #[ApiProperty(description: '`true` publishes the report, `false` discards it.', example: true)]
    #[Assert\NotNull]
    #[Groups(['agent_mission:decision'])]
    public ?bool $accepted = null;

    public function __construct(
        public readonly AgentMission $mission,
    ) {
    }
}
