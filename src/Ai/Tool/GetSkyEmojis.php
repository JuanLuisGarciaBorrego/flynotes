<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Repository\ParticipantRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'get_sky_emojis',
    description: 'Returns aggregated emoji usage among registered FlyNotes participants, for every emoji in use. These emojis are profile symbols, not verified emotions.',
)]
final readonly class GetSkyEmojis
{
    public function __construct(
        private ParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @return list<array{emoji: string, total: int}>
     */
    public function __invoke(): array
    {
        return $this->participantRepository->countByEmoji();
    }
}
