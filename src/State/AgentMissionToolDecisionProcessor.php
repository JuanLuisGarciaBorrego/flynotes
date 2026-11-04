<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AgentMission;
use App\Dto\AgentMissionToolDecision;
use App\Manager\AgentMissionManager;

/**
 * @implements ProcessorInterface<AgentMissionToolDecision, AgentMission>
 */
final readonly class AgentMissionToolDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private AgentMissionManager $agentMissionManager,
    ) {
    }

    /** @param AgentMissionToolDecision $data validated input (`accepted` is not null) */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AgentMission
    {
        return AgentMission::fromEntity($this->agentMissionManager->decideTool($data->mission, (bool) $data->accepted));
    }
}
