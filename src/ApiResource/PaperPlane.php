<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Entity\PaperPlane as PaperPlaneEntity;
use App\Enum\AiMessageStatus;
use App\Exception\AiMessageNotAllowedException;
use App\Exception\AiMessageStateException;
use App\Exception\AiUnavailableException;
use App\Manager\PaperPlaneManager;
use App\OpenApi\FlyNotesOpenApiFactory;
use App\State\PaperPlane\PaperPlaneAiDecisionProcessor;
use App\State\PaperPlane\PaperPlaneAiRetryProcessor;
use App\State\PaperPlane\PaperPlaneProcessor;
use App\State\PaperPlane\PaperPlaneProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public HTTP representation of a paper plane. Only `message` is accepted as input on creation;
 * the author is always the authenticated participant. The collection returns
 * only the uuids of PaperPlaneManager::RANDOM_LIMIT random planes (not client-configurable), and whether each one is a
 * Sky Pulse report, so that the sky can paint it; GET one reveals its
 * message, author and passport of stamps (all public) plus what the current visitor can do. There is no HTML page per plane: the home reads it in a modal.
 *
 * AI reinterpretation (human in the loop, PaperPlaneAiManager): creation attaches a pending proposal; its author can
 * ask for another (`/ai-message/retry`, no body) or decide (`/ai-message/decision`, `{"accepted": bool, "versionId": uuid}`,
 * versionId required to accept: the latest one, from `aiVersionId`; a new version replaces the previous). Both answer
 * with this same representation.
 * `aiMessage` is public only once accepted; the author also sees the pending proposal. The AI fields are read-only.
 */
#[ApiResource(
    shortName: 'PaperPlane',
    operations: [
        new GetCollection(
            uriTemplate: '/paper_planes',
            openapi: new Model\Operation(
                operationId: 'discoverPaperPlanes',
                tags: ['Paper planes'],
                summary: 'Discover random paper planes',
                description: 'Returns up to '.PaperPlaneManager::RANDOM_LIMIT.' random planes, a different sample on each request. Each item only says which plane it is and whether Sky Pulse published it: read its detail to see the message. Not paginated.',
            ),
            paginationEnabled: false,
            normalizationContext: ['groups' => ['paper_plane:list']],
            provider: PaperPlaneProvider::class,
        ),
        new Get(
            uriTemplate: '/paper_planes/{uuid}',
            requirements: ['uuid' => Requirement::UUID],
            openapi: new Model\Operation(
                operationId: 'readPaperPlane',
                tags: ['Paper planes'],
                summary: 'Read a paper plane',
                description: 'Returns the message, author and stamps of a plane, plus what the current visitor can do with it. Reading a plane adds to its `viewCount`, except for its author. A pending AI version is only shown to the author.',
            ),
            normalizationContext: ['groups' => ['paper_plane:read', 'paper_plane:detail']],
            provider: PaperPlaneProvider::class,
        ),
        new Post(
            uriTemplate: '/paper_planes',
            openapi: new Model\Operation(
                operationId: 'launchPaperPlane',
                tags: ['Paper planes'],
                responses: [
                    201 => new Model\Response(description: 'Plane launched. The AI proposal is optional and may be unavailable: `aiStatus` is then null.'),
                ],
                summary: 'Launch a paper plane',
                description: 'Publishes a message as the authenticated human and asks the AI for a first reinterpretation, left pending for the author to approve or reject. If the AI fails or is unavailable, the plane is launched anyway, without a proposal.',
                requestBody: new Model\RequestBody(description: 'The message, from '.PaperPlaneEntity::MESSAGE_MIN_LENGTH.' to '.PaperPlaneEntity::MESSAGE_MAX_LENGTH.' characters.', required: true),
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            normalizationContext: ['groups' => ['paper_plane:read']],
            denormalizationContext: ['groups' => ['paper_plane:write']],
            security: "is_granted('ROLE_HUMAN')",
            processor: PaperPlaneProcessor::class,
        ),
        new Post(
            uriTemplate: '/paper_planes/{uuid}/ai-message/retry',
            uriVariables: ['uuid'],
            requirements: ['uuid' => Requirement::UUID],
            status: Response::HTTP_OK,
            openapi: new Model\Operation(
                operationId: 'retryPaperPlaneAiMessage',
                tags: ['Paper planes'],
                responses: [
                    200 => new Model\Response(description: 'A new AI version was generated.'),
                    409 => new Model\Response(description: 'The AI message was already decided, no generations are left, or the AI is still writing a version.'),
                    503 => new Model\Response(description: 'AI is temporarily unavailable.'),
                ],
                summary: 'Request another AI version',
                description: 'Generates a new AI reinterpretation, up to '.PaperPlaneEntity::AI_MAX_ATTEMPTS.' generations per plane. It replaces the previous version, which stays stored but can no longer be approved. A failed generation spends none and changes nothing. Only for the author. Takes no request body.',
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: self::AI_EXCEPTION_TO_STATUS,
            normalizationContext: ['groups' => ['paper_plane:read']],
            security: "is_granted('ROLE_HUMAN')",
            // No request body; the processor loads the plane from the URL (404 if missing).
            input: false,
            read: false,
            processor: PaperPlaneAiRetryProcessor::class,
        ),
        new Post(
            uriTemplate: '/paper_planes/{uuid}/ai-message/decision',
            uriVariables: ['uuid'],
            requirements: ['uuid' => Requirement::UUID],
            status: Response::HTTP_OK,
            openapi: new Model\Operation(
                operationId: 'decidePaperPlaneAiMessage',
                tags: ['Paper planes'],
                responses: [
                    200 => new Model\Response(description: 'Decision recorded.'),
                    409 => new Model\Response(description: 'The AI message was already decided, there is nothing to decide, the version is not the latest of this plane, or the AI is still writing a new one.'),
                ],
                summary: 'Approve or reject the AI version',
                description: 'Records the decision of the author, the only one who can make it. Approving publishes the chosen version next to the original message; rejecting discards every version, and only the original flies.',
                requestBody: new Model\RequestBody(description: '`accepted: true` with the `versionId` to publish, or `accepted: false` alone.', required: true),
                security: FlyNotesOpenApiFactory::AUTHENTICATION,
            ),
            exceptionToStatus: self::AI_EXCEPTION_TO_STATUS,
            normalizationContext: ['groups' => ['paper_plane:read']],
            denormalizationContext: ['groups' => ['paper_plane:ai_decision']],
            security: "is_granted('ROLE_HUMAN')",
            validationContext: ['groups' => ['ai_decision']],
            read: false,
            processor: PaperPlaneAiDecisionProcessor::class,
        ),
    ],
)]
final class PaperPlane
{
    private const array AI_EXCEPTION_TO_STATUS = [
        AiMessageNotAllowedException::class => Response::HTTP_FORBIDDEN,
        AiMessageStateException::class => Response::HTTP_CONFLICT,
        AiUnavailableException::class => Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    #[ApiProperty(writable: false, identifier: true, description: 'Public identifier of the plane.', example: '8f14e45f-ceea-467f-a8d9-1a2b3c4d5e6f')]
    #[Groups(['paper_plane:list', 'paper_plane:read'])]
    public ?string $uuid = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(min: PaperPlaneEntity::MESSAGE_MIN_LENGTH, max: PaperPlaneEntity::MESSAGE_MAX_LENGTH, normalizer: 'trim')]
    #[Groups(['paper_plane:read', 'paper_plane:write'])]
    #[ApiProperty(description: 'The original message, which never changes.', example: 'See you at the airfield on Saturday!')]
    public ?string $message = null;

    /**
     * AI reinterpretation of `message` (which stays the original). Public readers get it only once the author
     * accepted it; the author also gets the pending proposal. Null otherwise.
     */
    #[ApiProperty(writable: false, description: 'AI reinterpretation of `message`. Public once the author approved it; the author also sees the pending proposal. Null otherwise.', example: 'SEE YOU AT THE AIRFIELD ON SATURDAY, AND DO NOT BE LATE!')]
    #[Groups(['paper_plane:read'])]
    public ?string $aiMessage = null;

    /** Public id of the version in `aiMessage`: send it back as `versionId` to approve exactly that one. */
    #[ApiProperty(writable: false, description: 'Identifier of the version in `aiMessage`: send it back as `versionId` to approve exactly that one.', example: '0b6e1f3a-7c1d-4a55-9e21-6f2d8c1b7a90')]
    #[Groups(['paper_plane:read'])]
    public ?string $aiVersionId = null;

    /** Tone of the version in `aiMessage`, as the generator labelled it. Same visibility as `aiMessage`. */
    #[ApiProperty(writable: false, description: 'Tone of the version in `aiMessage`, as the AI labelled it. Same visibility as `aiMessage`.', example: 'Impatient')]
    #[Groups(['paper_plane:read'])]
    public ?string $aiTone = null;

    /** Null when no reinterpretation was ever generated. */
    #[ApiProperty(writable: false, description: 'Where the review of the AI version stands. Null if the AI never wrote one.', example: 'pending')]
    #[Groups(['paper_plane:read'])]
    public ?AiMessageStatus $aiStatus = null;

    /** AI generations performed (initial proposal plus retries). */
    #[ApiProperty(writable: false, description: 'AI generations performed: the first proposal plus each retry.', example: 1)]
    #[Groups(['paper_plane:read'])]
    public int $aiAttempts = 0;

    /** Generations still allowed (computed, not stored). */
    #[ApiProperty(writable: false, description: 'AI generations still allowed.', example: 2)]
    #[Groups(['paper_plane:read'])]
    public int $aiAttemptsRemaining = PaperPlaneEntity::AI_MAX_ATTEMPTS;

    /** Input of `/ai-message/decision` only: true approves `versionId`, false discards every version. */
    #[ApiProperty(readable: false, description: '`true` publishes the version in `versionId`; `false` discards every version.', example: true)]
    #[Assert\NotNull(groups: ['ai_decision'])]
    #[Groups(['paper_plane:ai_decision'])]
    public ?bool $accepted = null;

    /** Input of `/ai-message/decision` only: the version the author saw (`aiVersionId`); required to accept. */
    #[ApiProperty(readable: false)]
    #[Assert\When(
        expression: 'this.accepted === true',
        constraints: [new Assert\NotBlank(), new Assert\Uuid()],
        groups: ['ai_decision'],
    )]
    #[Groups(['paper_plane:ai_decision'])]
    #[ApiProperty(description: 'The version to publish: the latest `aiVersionId`. Required when `accepted` is `true`.', example: '0b6e1f3a-7c1d-4a55-9e21-6f2d8c1b7a90')]
    public ?string $versionId = null;

    /** Published by the Sky Pulse agent, by its reserved identity and not by its name. Never exposes that identity. */
    #[ApiProperty(writable: false, description: 'Whether Sky Pulse published this plane, after human approval. Decided by the reserved identity of the agent, never by its display name.', example: false)]
    #[Groups(['paper_plane:list', 'paper_plane:detail'])]
    public bool $isSkyPulseReport = false;

    #[ApiProperty(writable: false, description: 'Display name of the author.', example: 'Ada')]
    #[Groups(['paper_plane:read'])]
    public ?string $authorName = null;

    #[ApiProperty(writable: false, description: 'Profile emoji of the author.', example: '😎')]
    #[Groups(['paper_plane:read'])]
    public ?string $authorEmoji = null;

    #[ApiProperty(writable: false, description: 'ISO 3166-1 alpha-2 country of the author.', example: 'ES')]
    #[Groups(['paper_plane:detail'])]
    public ?string $authorCountryCode = null;

    #[ApiProperty(writable: false, description: 'When the plane was launched, in ISO 8601.', example: '2026-11-04T18:30:00+00:00')]
    #[Groups(['paper_plane:detail'])]
    public ?string $createdAt = null;

    /**
     * The plane's passport, oldest first: only what the stamp stored (never participant identity or codes).
     * `uuid` is the stamp's own id (not the participant's): the client derives its position from it.
     *
     * @var list<array{uuid: string, countryCode: ?string, countryName: ?string, color: string, emoji: ?string, createdAt: ?string}>
     */
    #[ApiProperty(writable: false, description: 'The passport of the plane, oldest first: only what each stamp stored, never who left it.')]
    #[Groups(['paper_plane:detail'])]
    public array $stamps = [];

    /** Visitor-dependent flags, always computed by the server. */
    #[ApiProperty(writable: false, description: 'Whether the request is authenticated.', example: true)]
    #[Groups(['paper_plane:detail'])]
    public bool $isAuthenticated = false;

    #[ApiProperty(writable: false, description: 'Whether the visitor launched this plane.', example: false)]
    #[Groups(['paper_plane:detail'])]
    public bool $isAuthor = false;

    #[ApiProperty(writable: false, description: 'Whether the visitor already stamped this plane.', example: false)]
    #[Groups(['paper_plane:detail'])]
    public bool $hasStamped = false;

    #[ApiProperty(writable: false, description: 'Whether the visitor can stamp this plane now.', example: true)]
    #[Groups(['paper_plane:detail'])]
    public bool $canStamp = false;

    /** Times the plane was opened by someone other than its author (includes the current read). */
    #[ApiProperty(writable: false, description: 'Times the plane was read by someone other than its author, including this read.', example: 12)]
    #[Groups(['paper_plane:detail'])]
    public int $viewCount = 0;

    /** Whether the visitor already stamped this plane; null (not false) when there is no session. */
    #[ApiProperty(writable: false, description: 'Whether the visitor stamped this plane. Null without authentication.', example: false)]
    #[Groups(['paper_plane:detail'])]
    public ?bool $stampedByMe = null;

    /** The visitor's own stamp data (country and emoji they would stamp with); null for anonymous visitors. */
    #[ApiProperty(writable: false, description: 'Emoji the visitor would stamp with. Null without authentication.', example: '🤓')]
    #[Groups(['paper_plane:detail'])]
    public ?string $viewerEmoji = null;

    #[ApiProperty(writable: false, description: 'Country code the visitor would stamp with. Null without authentication.', example: 'FR')]
    #[Groups(['paper_plane:detail'])]
    public ?string $viewerCountryCode = null;

    #[ApiProperty(writable: false, description: 'Name of that country. Null without authentication.', example: 'France')]
    #[Groups(['paper_plane:detail'])]
    public ?string $viewerCountryName = null;

    /**
     * Fields shared by every read of a plane. The AI version is the only private part: anyone sees the approved one,
     * only the author ($forAuthor) sees a pending proposal; discarded versions are never exposed. Keep this the single place that rule is applied.
     */
    public static function fromEntity(PaperPlaneEntity $plane, bool $forAuthor): self
    {
        $resource = new self();
        $resource->uuid = $plane->getUuid()->toRfc4122();
        $resource->message = $plane->getMessage();
        $resource->isSkyPulseReport = $plane->getCreatedBy()?->isSkyPulse() ?? false;
        $resource->authorName = $plane->getCreatedBy()?->getName();
        $resource->authorEmoji = $plane->getCreatedBy()?->getEmoji();
        $resource->aiStatus = $plane->getAiStatus();
        // Published: the approved version. The author also sees the pending proposal (the latest version).
        $version = $plane->getApprovedAiVersion()
            ?? ($forAuthor && AiMessageStatus::Pending === $plane->getAiStatus() ? $plane->getCurrentAiVersion() : null);
        $resource->aiMessage = $version?->getText();
        $resource->aiVersionId = $version?->getUuid()->toRfc4122();
        $resource->aiTone = $version?->getTone();
        $resource->aiAttempts = $plane->getAiAttempts();
        $resource->aiAttemptsRemaining = $plane->getAiAttemptsRemaining();

        return $resource;
    }
}
