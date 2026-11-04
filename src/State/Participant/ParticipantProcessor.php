<?php

declare(strict_types=1);

namespace App\State\Participant;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Participant as ParticipantResource;
use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Manager\ParticipantManager;

/**
 * @implements ProcessorInterface<ParticipantResource, ParticipantResource>
 */
final readonly class ParticipantProcessor implements ProcessorInterface
{
    public function __construct(
        private ParticipantManager $participantManager,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ParticipantResource
    {
        // Input was already validated by API Platform. HTTP can only create humans.
        $participant = $this->participantManager->create(ParticipantType::Human, $data->name, $data->countryCode);

        // Starts the authenticated session (HttpOnly cookie); the code itself is never stored in it.
        $this->currentParticipant->login($participant);

        return ParticipantResource::fromEntity($participant);
    }
}
