<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AgentMission;
use App\Repository\AgentMissionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only: null becomes a 404 (the operation sets `throwOnNotFound`).
 *
 * @implements ProviderInterface<AgentMission>
 */
final readonly class AgentMissionProvider implements ProviderInterface
{
    public function __construct(
        private AgentMissionRepository $missions,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AgentMission
    {
        // The route requirement guarantees a syntactically valid UUID.
        $mission = $this->missions->findOneBy(['uuid' => Uuid::fromString($uriVariables['uuid'])]);

        return null === $mission ? null : AgentMission::fromEntity($mission);
    }
}
