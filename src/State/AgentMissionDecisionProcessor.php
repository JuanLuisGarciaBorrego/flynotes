<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AgentMission;
use App\Dto\AgentMissionDecision;
use App\Manager\AgentMissionManager;

/**
 * @implements ProcessorInterface<AgentMissionDecision, AgentMission>
 */
final readonly class AgentMissionDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private AgentMissionManager $agentMissionManager,
    ) {
    }

    /** @param AgentMissionDecision $data validated input (`accepted` is not null) */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AgentMission
    {
        return AgentMission::fromEntity($this->agentMissionManager->decide($data->mission, (bool) $data->accepted));
    }
}
