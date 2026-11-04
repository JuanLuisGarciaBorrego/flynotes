<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Entity\Participant as ParticipantEntity;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\Participant\ParticipantProcessor;
use App\State\Participant\ParticipantProfileProvider;
use App\State\Participant\ParticipantRecoveryProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public HTTP representation of a participant. Independent of Doctrine.
 *
 * - Registration (`POST /participants`): input is `name` and `countryCode` (both required).
 * - Recovery (`POST /participants/recover`): input is only `code`; exchanges it for a web session.
 * - Profile (`GET /participants/me`): the human authenticated by the web session; no identifier is accepted.
 *
 * Registration and recovery return the same output, including `code` (the participant's personal secret; on recovery the
 * caller already knows it). It never travels in cookies or URLs nor is logged, and neither response is cached. The web session cookie
 * is established by the processors. The profile uses its own `participant:profile` group, which never includes `code`.
 */
#[ApiResource(
    shortName: 'Participant',
    operations: [
        new Post(
            uriTemplate: '/participants',
            headers: self::NO_STORE,
            openapi: new Model\Operation(
                operationId: 'registerParticipant',
                tags: ['Participants'],
                responses: [
                    201 => new Model\Response(description: 'Participant created and session started.'),
                    429 => new Model\Response(description: 'Too many registrations from this client. `Retry-After` says when to try again.'),
                ],
                summary: 'Register a human participant',
                description: 'Creates a human participant with a random emoji and starts its web session. The response carries `code`, the personal secret that recovers this identity and authenticates API clients: store it safely. AI agents register through MCP instead.',
                requestBody: new Model\RequestBody(description: 'The nickname and country of the new participant.', required: true),
            ),
            normalizationContext: ['groups' => ['participant:read']],
            denormalizationContext: ['groups' => ['participant:register']],
            validationContext: ['groups' => ['register']],
            processor: ParticipantProcessor::class,
        ),
        new Post(
            uriTemplate: '/participants/recover',
            status: Response::HTTP_OK,
            headers: self::NO_STORE,
            openapi: new Model\Operation(
                operationId: 'recoverParticipant',
                tags: ['Participants'],
                responses: [
                    200 => new Model\Response(description: 'Identity recovered and session started.'),
                    400 => new Model\Response(description: 'The code is not valid. An unknown code and a malformed one get the same answer.'),
                    429 => new Model\Response(description: 'Too many attempts from this client. `Retry-After` says when to try again.'),
                ],
                summary: 'Recover an identity with its code',
                description: 'Exchanges the `code` of a human participant for a new web session. Creates nothing. Every attempt counts towards the limit, also the successful ones.',
                requestBody: new Model\RequestBody(description: 'The recovery code of the participant.', required: true),
            ),
            normalizationContext: ['groups' => ['participant:read']],
            denormalizationContext: ['groups' => ['participant:recover']],
            validationContext: ['groups' => ['recover']],
            processor: ParticipantRecoveryProcessor::class,
        ),
        // Item IRI (`@id`) of every response, always answering 404. API Platform only generates it on its own
        // while the resource has no item GET; it must stay before `/participants/me`, which would otherwise be
        // taken as the IRI. The UUID requirement keeps it from matching `/participants/me`.
        new NotExposed(
            uriTemplate: '/participants/{uuid}',
            requirements: ['uuid' => Requirement::UUID],
        ),
        new Get(
            uriTemplate: '/participants/me',
            headers: self::NO_STORE,
            openapi: new Model\Operation(
                operationId: 'getMyProfile',
                tags: ['Participants'],
                summary: 'Get my profile',
                description: 'Returns the authenticated human, without the recovery code. Takes no identifier: it can only ever return yourself.',
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            // The web session or a human's Bearer identify the participant. Anonymous gets 401; a bot's Bearer is
            // rejected with a 401 too, and a bot session (which no route can start) would get a 403.
            normalizationContext: ['groups' => ['participant:profile']],
            security: "is_granted('ROLE_HUMAN')",
            provider: ParticipantProfileProvider::class,
        ),
    ],
)]
final class Participant
{
    /**
     * Registration and recovery carry the recovery code, and the profile is personal: no HTTP cache
     * (shared or private) may keep them.
     * Not `cacheHeaders`: API Platform only applies those to cacheable (GET/HEAD) requests.
     */
    private const array NO_STORE = ['Cache-Control' => 'no-store'];

    #[ApiProperty(writable: false, identifier: true, description: 'Public identifier of the participant.', example: '8f14e45f-ceea-467f-a8d9-1a2b3c4d5e6f')]
    #[Groups(['participant:read', 'participant:profile'])]
    public ?string $uuid = null;

    #[Assert\NotBlank(normalizer: 'trim', groups: ['register'])]
    #[Assert\Length(max: ParticipantEntity::NAME_MAX_LENGTH, groups: ['register'])]
    #[Groups(['participant:read', 'participant:register', 'participant:profile'])]
    #[ApiProperty(description: 'Nickname of the participant.', example: 'Ada')]
    public ?string $name = null;

    #[Groups(['participant:read', 'participant:profile'])]
    #[ApiProperty(description: 'Profile emoji, chosen at random on registration.', example: '😎')]
    public ?string $emoji = null;

    #[Assert\NotBlank(message: 'The country is required.', groups: ['register'])]
    #[Assert\Country(message: 'The country code must be an uppercase ISO 3166-1 alpha-2 code (e.g. ES).', groups: ['register'])]
    #[Groups(['participant:read', 'participant:register', 'participant:profile'])]
    #[ApiProperty(description: 'Uppercase ISO 3166-1 alpha-2 country code.', example: 'ES')]
    public ?string $countryCode = null;

    #[Assert\NotBlank(normalizer: 'trim', groups: ['recover'])]
    #[Assert\Length(max: 64, groups: ['recover'])]
    #[Groups(['participant:read', 'participant:recover'])]
    #[ApiProperty(description: 'Personal secret that recovers this identity and authenticates API clients. Keep it private: it is never logged or sent in cookies or URLs.')]
    public ?string $code = null;

    public static function fromEntity(ParticipantEntity $participant): self
    {
        $resource = new self();
        $resource->uuid = $participant->getUuid()->toRfc4122();
        $resource->name = $participant->getName();
        $resource->emoji = $participant->getEmoji();
        $resource->countryCode = $participant->getCountryCode();
        $resource->code = $participant->getCode();

        return $resource;
    }
}
