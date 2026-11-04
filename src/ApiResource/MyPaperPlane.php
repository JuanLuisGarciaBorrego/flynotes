<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\Manager\PaperPlaneManager;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\MyPaperPlane\MyPaperPlaneProvider;

/**
 * One of the authenticated participant's own planes ("My planes"), with its statistics.
 * The owner is always the session's participant, never a client parameter. Each item's IRI is the
 * public detail (GET /paper_planes/{uuid}); listing never registers a visit.
 */
#[ApiResource(
    shortName: 'MyPaperPlane',
    operations: [
        new GetCollection(
            uriTemplate: '/me/paper_planes',
            openapi: new Model\Operation(
                operationId: 'listMyPaperPlanes',
                tags: ['My planes'],
                summary: 'List my paper planes',
                description: 'Returns the planes launched by the authenticated human with their views and stamps, '.self::ITEMS_PER_PAGE.' per page. Listing them does not count as reading them.',
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            paginationItemsPerPage: self::ITEMS_PER_PAGE,
            paginationClientItemsPerPage: false,
            security: "is_granted('ROLE_HUMAN')",
            provider: MyPaperPlaneProvider::class,
            itemUriTemplate: '/paper_planes/{uuid}',
        ),
    ],
)]
final readonly class MyPaperPlane
{
    public const int ITEMS_PER_PAGE = PaperPlaneManager::OWN_PER_PAGE;

    /**
     * @param list<array{uuid: string, countryCode: ?string, color: string}> $stamps oldest first:
     *                                                                               only what the stamp component paints
     */
    public function __construct(
        #[ApiProperty(identifier: true, description: 'Public identifier of the plane.', example: '8f14e45f-ceea-467f-a8d9-1a2b3c4d5e6f')]
        public string $uuid,
        #[ApiProperty(description: 'The original message.', example: 'See you at the airfield on Saturday!')]
        public string $message,
        #[ApiProperty(description: 'When the plane was launched.', example: '2026-11-04T18:30:00+00:00')]
        public \DateTimeImmutable $createdAt,
        /** Times the plane was opened by someone other than its author. */
        #[ApiProperty(description: 'Times the plane was read by someone other than its author.', example: 12)]
        public int $viewCount,
        #[ApiProperty(description: 'Stamps received.', example: 3)]
        public int $stampCount,
        #[ApiProperty(description: 'Stamps of the plane, oldest first: only their identifier, country and colour.')]
        public array $stamps,
    ) {
    }
}
