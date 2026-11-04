<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Repository\ParticipantRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'get_sky_countries',
    description: 'Returns aggregated participant counts by country using ISO 3166-1 alpha-2 codes, for every country with at least one participant.',
)]
final readonly class GetSkyCountries
{
    public function __construct(
        private ParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @return list<array{countryCode: string, total: int}>
     */
    public function __invoke(): array
    {
        return $this->participantRepository->countByCountry();
    }
}
