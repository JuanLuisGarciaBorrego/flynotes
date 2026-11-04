<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Participant;
use App\Helper\CurrentParticipantHelper;
use Twig\Attribute\AsTwigFunction;

final readonly class CurrentParticipantExtension
{
    public function __construct(private CurrentParticipantHelper $currentParticipant)
    {
    }

    /** The registered human of this request (identity cookie), or null. Never exposes the code. */
    #[AsTwigFunction('current_participant')]
    public function current(): ?Participant
    {
        return $this->currentParticipant->get();
    }
}
