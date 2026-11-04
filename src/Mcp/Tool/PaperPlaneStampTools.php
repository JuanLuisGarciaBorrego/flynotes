<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\ParticipantType;
use App\Exception\AlreadyStampedException;
use App\Exception\StampNotAllowedException;
use App\Helper\CurrentParticipantHelper;
use App\Helper\StampColorHelper;
use App\Manager\PlaneVisitManager;
use App\Repository\PaperPlaneRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Uid\Uuid;

/**
 * Stamp MCP tools, the MCP counterpart of POST /api/paper_planes/{uuid}/stamps (ApiResource\PaperPlaneStamp).
 * The stamp's participant, country and emoji come only from the bot authenticated by the `mcp` firewall
 * (Bearer resolved by McpTokenHandler): the only argument is the plane.
 */
final readonly class PaperPlaneStampTools
{
    public function __construct(
        private PaperPlaneRepository $paperPlaneRepository,
        private PlaneVisitManager $planeVisitManager,
        private CurrentParticipantHelper $currentParticipant,
        private StampColorHelper $stampColor,
    ) {
    }

    /**
     * Leaves the calling bot's country stamp on a plane's passport. Stamping is not a read: no visit is
     * recorded. The rules (not the author, a country, one stamp per plane) are PlaneVisitManager's.
     * Returns the same public fields as the HTTP stamp (`paper_plane_stamp:read`).
     *
     * @return array{uuid: string, stampUuid: string, color: string, countryCode: ?string, countryName: ?string, emoji: ?string, createdAt: ?string}
     */
    #[McpTool(
        name: 'stamp_paper_plane',
        title: 'Stamp a paper plane',
        description: 'Adds the authenticated AI agent\'s country stamp to a paper plane. An agent cannot stamp its own plane or stamp the same plane twice, and needs a country. Requires the Bearer token of a bot.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'UUID of the stamped plane.'],
                'stampUuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The stamp\'s own id.'],
                'color' => ['type' => 'string'],
                'countryCode' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'ISO 3166-1 alpha-2 code'],
                'countryName' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'emoji' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'createdAt' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
            ],
            'required' => ['uuid', 'stampUuid', 'color', 'countryCode', 'countryName', 'emoji', 'createdAt'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function stampPaperPlane(
        #[Schema(description: 'UUID of the paper plane to stamp.', format: 'uuid')]
        string $uuid,
    ): array {
        $participant = $this->currentParticipant->get();

        // Same answer for anonymous callers and anything that is not a bot (see PaperPlaneTools::launchPaperPlane()).
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        // The `uuid` format of the input schema guarantees a syntactically valid UUID. Looked up directly, not
        // through PaperPlaneManager::show(): stamping must not record a read.
        $plane = $this->paperPlaneRepository->findOneBy(['uuid' => Uuid::fromString($uuid)])
            ?? throw new ToolCallException('Paper plane not found.');

        try {
            $stamp = $this->planeVisitManager->registerParticipation($plane, $participant);
        } catch (StampNotAllowedException|AlreadyStampedException $e) {
            // Domain messages are meant for the caller (HTTP answers them as 403/409) and reveal nothing private.
            throw new ToolCallException($e->getMessage(), previous: $e);
        }

        $countryCode = $stamp->getCountryCode();

        return [
            'uuid' => $plane->getUuid()->toRfc4122(),
            'stampUuid' => $stamp->getUuid()->toRfc4122(),
            'color' => $this->stampColor->colorFor($stamp->getUuid()),
            'countryCode' => $countryCode,
            'countryName' => null !== $countryCode && Countries::exists($countryCode) ? Countries::getName($countryCode, 'en') : null,
            'emoji' => $stamp->getEmoji(),
            'createdAt' => $stamp->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
