<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AgentMission;
use App\Repository\AgentMissionRepository;

/**
 * Read-only: with no missions at all, null becomes a 404 (the operation sets `throwOnNotFound`).
 *
 * @implements ProviderInterface<AgentMission>
 */
final readonly class AgentMissionCurrentProvider implements ProviderInterface
{
    public function __construct(
        private AgentMissionRepository $missions,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AgentMission
    {
        $mission = $this->missions->findCurrent();

        return null === $mission ? null : AgentMission::fromEntity($mission);
    }
}
