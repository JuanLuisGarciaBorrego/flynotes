<?php

declare(strict_types=1);

namespace App\State\Participant;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Participant;
use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * GET /participants/me: the human authenticated by the web session. The identity comes only from
 * Symfony Security, never from a client parameter.
 *
 * @implements ProviderInterface<Participant>
 */
final readonly class ParticipantProfileProvider implements ProviderInterface
{
    public function __construct(
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Participant
    {
        // The operation's security already requires ROLE_HUMAN; this only guards against a misconfiguration.
        $participant = $this->currentParticipant->get();
        if (null === $participant || ParticipantType::Human !== $participant->getType()) {
            throw new AccessDeniedException();
        }

        $resource = Participant::fromEntity($participant);
        // The `participant:profile` group already leaves it out; the secret is not even carried in memory.
        $resource->code = null;

        return $resource;
    }
}
