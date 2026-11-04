<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\AgentMissionToolDecision;
use App\Repository\AgentMissionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the mission from the URL (404 via the operation's `throwOnNotFound`), so that the validator and the
 * processor receive it inside the decision and no client data can point to another mission.
 *
 * @implements ProviderInterface<AgentMissionToolDecision>
 */
final readonly class AgentMissionToolDecisionProvider implements ProviderInterface
{
    public function __construct(
        private AgentMissionRepository $missions,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AgentMissionToolDecision
    {
        // The route requirement guarantees a syntactically valid UUID.
        $mission = $this->missions->findOneBy(['uuid' => Uuid::fromString($uriVariables['uuid'])]);

        return null === $mission ? null : new AgentMissionToolDecision($mission);
    }
}
