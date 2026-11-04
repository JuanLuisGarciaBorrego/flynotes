<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Exception\AlreadyStampedException;
use App\Exception\StampNotAllowedException;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\PaperPlaneStamp\PaperPlaneStampProcessor;
use App\State\PaperPlaneStamp\PaperPlaneStampProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * "Stamp my seal": the authenticated human leaves their country's stamp on a plane's passport.
 * There is no input: the plane comes from the URL, and participant, country and emoji from the session.
 */
#[ApiResource(
    shortName: 'PaperPlaneStamp',
    operations: [
        new Post(
            uriTemplate: '/paper_planes/{uuid}/stamps',
            uriVariables: ['uuid'],
            requirements: ['uuid' => Requirement::UUID],
            status: Response::HTTP_CREATED,
            openapi: new Model\Operation(
                operationId: 'stampPaperPlane',
                tags: ['Paper planes'],
                responses: [
                    201 => new Model\Response(description: 'Stamp added.'),
                    409 => new Model\Response(description: 'You already stamped this plane.'),
                ],
                summary: 'Stamp a paper plane',
                description: 'Adds the country stamp of the authenticated human to the passport of a plane. Takes no request body: the plane comes from the URL and everything else from your identity. Each plane can be stamped once per participant, and never by its author.',
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: [
                StampNotAllowedException::class => Response::HTTP_FORBIDDEN,
                AlreadyStampedException::class => Response::HTTP_CONFLICT,
            ],
            normalizationContext: ['groups' => ['paper_plane_stamp:read']],
            security: "is_granted('ROLE_HUMAN')",
            // No request body: the plane comes from the URL, everything else from the session.
            input: false,
            provider: PaperPlaneStampProvider::class,
            processor: PaperPlaneStampProcessor::class,
            throwOnNotFound: true,
        ),
    ],
)]
final class PaperPlaneStamp
{
    /** UUID of the stamped plane. */
    #[ApiProperty(writable: false, identifier: true, description: 'UUID of the stamped plane.', example: '8f14e45f-ceea-467f-a8d9-1a2b3c4d5e6f')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $uuid = null;

    /** The stamp's own uuid (position in the passport is derived from it). */
    #[ApiProperty(writable: false, description: 'Identifier of the stamp itself.', example: '3d2c1b0a-9f8e-4d7c-b6a5-443322110099')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $stampUuid = null;

    #[ApiProperty(writable: false, description: 'Colour of the stamp.', example: '#2F5DA8')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $color = null;

    #[ApiProperty(writable: false, description: 'ISO 3166-1 alpha-2 country of the stamp.', example: 'FR')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $countryCode = null;

    #[ApiProperty(writable: false, description: 'Name of that country.', example: 'France')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $countryName = null;

    #[ApiProperty(writable: false, description: 'Emoji of the participant who stamped.', example: '🤓')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $emoji = null;

    #[ApiProperty(writable: false, description: 'When it was stamped, in ISO 8601.', example: '2026-11-04T18:45:00+00:00')]
    #[Groups(['paper_plane_stamp:read'])]
    public ?string $createdAt = null;
}
