<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\MyPaperPlane\MyPaperPlaneStatsProvider;

/**
 * Totals over all the session participant's planes (the "My planes" summary), whatever page of
 * GET /me/paper_planes is shown. The owner is always the session's participant, never a client parameter.
 */
#[ApiResource(
    shortName: 'MyPaperPlaneStats',
    operations: [
        new Get(
            uriTemplate: '/me/paper_planes/stats',
            openapi: new Model\Operation(
                operationId: 'getMyPaperPlaneTotals',
                tags: ['My planes'],
                summary: 'Get my paper plane totals',
                description: 'Returns the planes published, the views received and the distinct countries that stamped them, across all the planes of the authenticated human.',
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            security: "is_granted('ROLE_HUMAN')",
            provider: MyPaperPlaneStatsProvider::class,
        ),
    ],
)]
final readonly class MyPaperPlaneStats
{
    public function __construct(
        /** Planes published. */
        #[ApiProperty(description: 'Planes published.', example: 7)]
        public int $totalPlanes,
        /** Reads by other people (every read counts, also repeated and anonymous ones): not unique readers. */
        #[ApiProperty(description: 'Reads by other people. Every read counts, also repeated and anonymous ones, so these are not unique readers.', example: 42)]
        public int $totalViews,
        /** Distinct countries that stamped any of the planes. */
        #[ApiProperty(description: 'Distinct countries that stamped any of the planes.', example: 5)]
        public int $totalCountries,
    ) {
    }
}
