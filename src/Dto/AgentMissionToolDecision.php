<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\AgentMission;
use App\Validator\MissionAwaitsToolApproval;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of POST /agent_missions/{uuid}/tool-decision. The mission comes from the URL
 * (AgentMissionToolDecisionProvider); only `accepted` is read from the body.
 */
#[MissionAwaitsToolApproval]
final class AgentMissionToolDecision
{
    #[ApiProperty(description: '`true` runs the tool, `false` ends the mission without running it.', example: true)]
    #[Assert\NotNull]
    #[Assert\Type('bool')]
    #[Groups(['agent_mission:tool_decision'])]
    public ?bool $accepted = null;

    public function __construct(
        public readonly AgentMission $mission,
    ) {
    }
}
