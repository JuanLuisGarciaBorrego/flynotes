<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Repository\PaperPlaneRepository;
use App\Repository\PlaneVisitRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'get_sky_activity',
    description: 'Returns aggregated FlyNotes activity statistics: total paper planes, recorded views and participation stamps. No personal information is exposed.',
)]
final readonly class GetSkyActivity
{
    public function __construct(
        private PaperPlaneRepository $paperPlaneRepository,
        private PlaneVisitRepository $planeVisitRepository,
    ) {
    }

    /**
     * @return array{planes: int, views: int, stamps: int}
     */
    public function __invoke(): array
    {
        return [
            'planes' => $this->paperPlaneRepository->count(),
            'views' => $this->planeVisitRepository->countViewsTotal(),
            'stamps' => $this->planeVisitRepository->countStampsTotal(),
        ];
    }
}
