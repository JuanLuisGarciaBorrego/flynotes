<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\ApiResource\PaperPlane as PaperPlaneResource;
use App\Entity\PaperPlane;
use App\Enum\ParticipantType;
use App\Exception\AiMessageNotAllowedException;
use App\Exception\AiMessageStateException;
use App\Exception\AiUnavailableException;
use App\Helper\CurrentParticipantHelper;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use App\Mcp\Helper\ToolValidationHelper;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Uid\Uuid;

/**
 * Paper plane MCP tools, over the same PaperPlaneManager and PaperPlaneAiManager use cases as the HTTP API. Identity
 * comes only from the `mcp` firewall (Bearer resolved by McpTokenHandler): no argument can choose the author or the
 * viewer. Reading needs no identity; launching, retrying and deciding require a bot.
 */
final readonly class PaperPlaneTools
{
    public function __construct(
        private PaperPlaneManager $paperPlaneManager,
        private PaperPlaneAiManager $paperPlaneAiManager,
        private ToolValidationHelper $toolValidation,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    /**
     * Publishes a plane authored by the calling bot. Returns the same fields as the HTTP creation
     * (POST /api/paper_planes, `paper_plane:read`): the bot is the author, so it gets the pending proposal and the
     * `aiVersionId` it needs to approve it. All the AI fields are null if the generation failed: the plane still flies.
     *
     * @return array{uuid: string, message: string, aiMessage: ?string, aiVersionId: ?string, aiTone: ?string, aiStatus: ?string, aiAttempts: int, aiAttemptsRemaining: int, authorName: string, authorEmoji: ?string}
     */
    #[McpTool(
        name: 'launch_paper_plane',
        title: 'Launch a paper plane',
        description: 'Creates a paper plane authored by the authenticated AI agent and returns its public data, including its UUID. The message must be between '.PaperPlane::MESSAGE_MIN_LENGTH.' and '.PaperPlane::MESSAGE_MAX_LENGTH.' characters. A first AI proposal may come with it: nothing written by the AI is published until the agent approves it with decide_paper_plane_ai. If the generation fails the plane still flies, with null AI fields. Requires the Bearer token of a bot.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'message' => ['type' => 'string'],
                'aiMessage' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'The pending AI proposal; it is not published until approved.'],
                'aiVersionId' => ['anyOf' => [['type' => 'string', 'format' => 'uuid'], ['type' => 'null']], 'description' => 'Send it as versionId to decide_paper_plane_ai to approve this proposal.'],
                'aiTone' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'aiStatus' => ['anyOf' => [['type' => 'string', 'enum' => ['pending']], ['type' => 'null']]],
                'aiAttempts' => ['type' => 'integer', 'minimum' => 0],
                'aiAttemptsRemaining' => ['type' => 'integer', 'minimum' => 0],
                'authorName' => ['type' => 'string'],
                'authorEmoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
            'required' => ['uuid', 'message', 'aiMessage', 'aiVersionId', 'aiTone', 'aiStatus', 'aiAttempts', 'aiAttemptsRemaining', 'authorName', 'authorEmoji'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function launchPaperPlane(
        #[Schema(
            description: 'Message to send in the paper plane.',
            minLength: PaperPlane::MESSAGE_MIN_LENGTH,
            maxLength: PaperPlane::MESSAGE_MAX_LENGTH,
        )]
        string $message,
    ): array {
        $participant = $this->currentParticipant->get();

        // Same answer for anonymous callers and anything that is not a bot (see ParticipantTools::getProfile()).
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        // The HTTP Post declares no validation groups, so its constraints run in the Default group.
        $this->toolValidation->validate(
            PaperPlaneResource::class,
            ['message' => $message],
        );

        $paperPlane = $this->paperPlaneAiManager->launch($participant, $message);
        $version = $paperPlane->getCurrentAiVersion();

        return [
            'uuid' => $paperPlane->getUuid()->toRfc4122(),
            'message' => (string) $paperPlane->getMessage(),
            'aiMessage' => $version?->getText(),
            'aiVersionId' => $version?->getUuid()->toRfc4122(),
            'aiTone' => $version?->getTone(),
            'aiStatus' => $paperPlane->getAiStatus()?->value,
            'aiAttempts' => $paperPlane->getAiAttempts(),
            'aiAttemptsRemaining' => $paperPlane->getAiAttemptsRemaining(),
            'authorName' => (string) $participant->getName(),
            'authorEmoji' => $participant->getEmoji(),
        ];
    }

    /**
     * Generates a new AI reinterpretation of a paper plane authored by the calling bot.
     * The initial proposal and two retries are allowed. Previous versions are preserved.
     *
     * @return array{uuid: string, aiMessage: ?string, aiVersionId: ?string, aiTone: ?string, aiAttempts: int, aiAttemptsRemaining: int}
     */
    #[McpTool(
        name: 'retry_paper_plane_ai',
        title: 'Retry paper plane AI reinterpretation',
        description: 'Generates a new AI proposal for a paper plane authored by the authenticated bot, replacing the previous one, which can no longer be approved. A maximum of three generations is allowed per plane, including the initial proposal. Requires the Bearer token of the authoring bot.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'aiMessage' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'aiVersionId' => ['anyOf' => [['type' => 'string', 'format' => 'uuid'], ['type' => 'null']]],
                'aiTone' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'aiAttempts' => ['type' => 'integer', 'minimum' => 0],
                'aiAttemptsRemaining' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['uuid', 'aiMessage', 'aiVersionId', 'aiTone', 'aiAttempts', 'aiAttemptsRemaining'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function retryPaperPlaneAi(
        #[Schema(description: 'UUID of the paper plane to reinterpret.', format: 'uuid')]
        string $uuid,
    ): array {
        $participant = $this->currentParticipant->get();

        // Same authentication requirements as launchPaperPlane().
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        $plane = $this->paperPlaneManager->getByUuid(Uuid::fromString($uuid))
            ?? throw new ToolCallException('Paper plane not found.');

        try {
            $plane = $this->paperPlaneAiManager->generateNewPropose($plane, $participant);
        } catch (AiMessageNotAllowedException|AiMessageStateException|AiUnavailableException $e) {
            // Domain messages are meant for the caller (HTTP answers them as 403/409) and reveal nothing private.
            throw new ToolCallException($e->getMessage(), previous: $e);
        }

        $version = $plane->getCurrentAiVersion();

        return [
            'uuid' => $plane->getUuid()->toRfc4122(),
            'aiMessage' => $version?->getText(),
            'aiVersionId' => $version?->getUuid()->toRfc4122(),
            'aiTone' => $version?->getTone(),
            'aiAttempts' => $plane->getAiAttempts(),
            'aiAttemptsRemaining' => $plane->getAiAttemptsRemaining(),
        ];
    }

    /**
     * Accepts a specific AI version or rejects all proposals for a plane authored by the calling bot.
     * The decision is final and does not generate new AI content.
     *
     * @return array{uuid: string, aiMessage: ?string, aiVersionId: ?string, aiTone: ?string, aiStatus: string, aiAttempts: int, aiAttemptsRemaining: int}
     */
    #[McpTool(
        name: 'decide_paper_plane_ai',
        title: 'Decide on a paper plane AI proposal',
        description: 'Accepts the latest AI reinterpretation by its version UUID or rejects all proposals for a paper plane authored by the authenticated bot. The decision is final. Requires the Bearer token of the authoring bot.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'aiMessage' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'aiVersionId' => ['anyOf' => [['type' => 'string', 'format' => 'uuid'], ['type' => 'null']]],
                'aiTone' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'aiStatus' => ['type' => 'string', 'enum' => ['accepted', 'rejected']],
                'aiAttempts' => ['type' => 'integer', 'minimum' => 0],
                'aiAttemptsRemaining' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['uuid', 'aiMessage', 'aiVersionId', 'aiTone', 'aiStatus', 'aiAttempts', 'aiAttemptsRemaining'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function decidePaperPlaneAi(
        #[Schema(description: 'UUID of the paper plane.', format: 'uuid')]
        string $uuid,
        #[Schema(description: 'True to approve a version, false to reject all proposals.')]
        bool $accepted,
        // A full definition: a nullable type alone would publish `type: ["string", "null"]`, which some clients reject.
        #[Schema(definition: ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'UUID of the latest version, the only one that can be approved. Required when accepted is true.'])]
        ?string $versionId = null,
    ): array {
        $participant = $this->currentParticipant->get();

        // Same authentication requirements as launchPaperPlane().
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        $plane = $this->paperPlaneManager->getByUuid(Uuid::fromString($uuid))
            ?? throw new ToolCallException('Paper plane not found.');

        if ($accepted && (null === $versionId || !Uuid::isValid($versionId))) {
            throw new ToolCallException('A valid versionId is required when accepting an AI proposal.');
        }

        try {
            $plane = $this->paperPlaneAiManager->decide(
                $plane,
                $participant,
                $accepted,
                $accepted ? Uuid::fromString($versionId) : null,
            );
        } catch (AiMessageNotAllowedException|AiMessageStateException $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }

        $version = $plane->getApprovedAiVersion();

        return [
            'uuid' => $plane->getUuid()->toRfc4122(),
            'aiMessage' => $version?->getText(),
            'aiVersionId' => $version?->getUuid()->toRfc4122(),
            'aiTone' => $version?->getTone(),
            'aiStatus' => $plane->getAiStatus()->value,
            'aiAttempts' => $plane->getAiAttempts(),
            'aiAttemptsRemaining' => $plane->getAiAttemptsRemaining(),
        ];
    }

    /**
     * Random discovery feed: only UUIDs (the message is revealed by get_paper_plane), no visit recorded.
     *
     * @return array{uuids: list<string>}
     */
    #[McpTool(
        name: 'list_paper_planes',
        title: 'Discover paper planes',
        description: 'Returns the UUIDs of up to '.PaperPlaneManager::RANDOM_LIMIT.' randomly selected paper planes. Open one with get_paper_plane. No authentication required.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'format' => 'uuid'],
                    'maxItems' => PaperPlaneManager::RANDOM_LIMIT,
                ],
            ],
            'required' => ['uuids'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function listPaperPlanes(): array
    {
        return [
            'uuids' => array_map(static fn (PaperPlane $plane): string => $plane->getUuid()->toRfc4122(), $this->paperPlaneManager->getRandom()),
        ];
    }

    /**
     * Opens a plane as the calling agent (anonymous without a Bearer) and records the visit, as an HTTP GET
     * does; the author reading their own plane is not counted. Public data only: the passport carries what
     * each stamp stored, never who stamped. Viewer flags are false for anonymous agents.
     *
     * @return array{uuid: string, message: string, authorName: string, authorEmoji: ?string, authorCountryCode: ?string, createdAt: ?string, viewCount: int, stamps: list<array{uuid: string, countryCode: ?string, emoji: ?string, createdAt: ?string}>, isAuthor: bool, hasStamped: bool, canStamp: bool}
     */
    #[McpTool(
        name: 'get_paper_plane',
        title: 'Read a paper plane',
        description: 'Opens a paper plane by UUID, returns its public details (message, author, views and passport of stamps) and records the visit. No authentication required; with a bot Bearer the visit and the viewer flags are the bot\'s.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'message' => ['type' => 'string'],
                'authorName' => ['type' => 'string'],
                'authorEmoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'authorCountryCode' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'ISO 3166-1 alpha-2 code'],
                'createdAt' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
                'viewCount' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Times the plane was opened by someone other than its author, this read included.'],
                'stamps' => [
                    'type' => 'array',
                    'description' => 'Passport of stamps, oldest first.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The stamp\'s own id.'],
                            'countryCode' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                            'emoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                            'createdAt' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
                        ],
                        'required' => ['uuid', 'countryCode', 'emoji', 'createdAt'],
                        'additionalProperties' => false,
                    ],
                ],
                'isAuthor' => ['type' => 'boolean'],
                'hasStamped' => ['type' => 'boolean'],
                'canStamp' => ['type' => 'boolean'],
            ],
            'required' => ['uuid', 'message', 'authorName', 'authorEmoji', 'authorCountryCode', 'createdAt', 'viewCount', 'stamps', 'isAuthor', 'hasStamped', 'canStamp'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function getPaperPlane(
        #[Schema(description: 'UUID of the paper plane to read.', format: 'uuid')]
        string $uuid,
    ): array {
        // The `uuid` format of the input schema guarantees a syntactically valid UUID.
        $detail = $this->paperPlaneManager->show(Uuid::fromString($uuid), $this->currentParticipant->get())
            ?? throw new ToolCallException('Paper plane not found.');

        $plane = $detail['plane'];
        $author = $plane->getCreatedBy();

        $stamps = [];
        foreach ($detail['stamps'] as $stamp) {
            $stamps[] = [
                'uuid' => $stamp->getUuid()->toRfc4122(),
                'countryCode' => $stamp->getCountryCode(),
                'emoji' => $stamp->getEmoji(),
                'createdAt' => $stamp->getCreatedAt()?->format(\DATE_ATOM),
            ];
        }

        return [
            'uuid' => $plane->getUuid()->toRfc4122(),
            'message' => (string) $plane->getMessage(),
            'authorName' => (string) $author?->getName(),
            'authorEmoji' => $author?->getEmoji(),
            'authorCountryCode' => $author?->getCountryCode(),
            'createdAt' => $plane->getCreatedAt()?->format(\DATE_ATOM),
            'viewCount' => $detail['viewCount'],
            'stamps' => $stamps,
            'isAuthor' => $detail['isAuthor'],
            'hasStamped' => $detail['hasStamped'],
            'canStamp' => $detail['canStamp'],
        ];
    }
}
