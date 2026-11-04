<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AgentMission;
use App\Manager\AgentMissionManager;

/**
 * @implements ProcessorInterface<AgentMission, AgentMission>
 */
final readonly class AgentMissionProcessor implements ProcessorInterface
{
    public function __construct(
        private AgentMissionManager $agentMissionManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AgentMission
    {
        return AgentMission::fromEntity($this->agentMissionManager->launch());
    }
}
