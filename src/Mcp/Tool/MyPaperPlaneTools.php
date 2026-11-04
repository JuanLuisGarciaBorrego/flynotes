<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * "My planes" MCP tools, the MCP counterpart of GET /api/me/paper_planes (ApiResource\MyPaperPlane), over the
 * same PaperPlaneManager::getOwn() use case. The owner is always the bot authenticated by the `mcp` firewall
 * (Bearer resolved by McpTokenHandler): no argument can choose whose planes are listed.
 */
final readonly class MyPaperPlaneTools
{
    public function __construct(
        private PaperPlaneManager $paperPlaneManager,
        private CurrentParticipantHelper $currentParticipant,
        private StampColorHelper $stampColor,
    ) {
    }

    /**
     * One page of the calling bot's planes, newest first, with the same item fields as the HTTP collection.
     * Listing records no visits.
     *
     * @return array{page: int, itemsPerPage: int, totalItems: int, planes: list<array{uuid: string, message: string, createdAt: ?string, viewCount: int, stampCount: int, stamps: list<array{uuid: string, countryCode: ?string, color: string}>}>}
     */
    #[McpTool(
        name: 'list_my_paper_planes',
        title: 'List my paper planes',
        description: 'Returns paper planes created by the authenticated AI agent, newest first, '.PaperPlaneManager::OWN_PER_PAGE.' per page, each with its view count and stamps. Requires the Bearer token of a bot.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'itemsPerPage' => ['type' => 'integer'],
                'totalItems' => ['type' => 'integer', 'description' => 'Planes created by the agent, across all pages.'],
                'planes' => [
                    'type' => 'array',
                    'maxItems' => PaperPlaneManager::OWN_PER_PAGE,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid' => ['type' => 'string', 'format' => 'uuid'],
                            'message' => ['type' => 'string'],
                            'createdAt' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
                            'viewCount' => ['type' => 'integer', 'description' => 'Times the plane was opened by someone other than its author.'],
                            'stampCount' => ['type' => 'integer'],
                            'stamps' => [
                                'type' => 'array',
                                'description' => 'Oldest first.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'uuid' => ['type' => 'string', 'format' => 'uuid'],
                                        'countryCode' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'ISO 3166-1 alpha-2 code'],
                                        'color' => ['type' => 'string'],
                                    ],
                                    'required' => ['uuid', 'countryCode', 'color'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['uuid', 'message', 'createdAt', 'viewCount', 'stampCount', 'stamps'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['page', 'itemsPerPage', 'totalItems', 'planes'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function listMyPaperPlanes(
        #[Schema(description: 'Page number, starting at 1.', minimum: 1)]
        int $page = 1,
    ): array {
        $participant = $this->currentParticipant->get();

        // Same answer for anonymous callers and anything that is not a bot (see PaperPlaneTools::launchPaperPlane()).
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        ['items' => $items, 'total' => $total] = $this->paperPlaneManager->getOwn($participant, $page);

        return [
            'page' => $page,
            'itemsPerPage' => PaperPlaneManager::OWN_PER_PAGE,
            'totalItems' => $total,
            'planes' => array_map(fn (array $item): array => [
                'uuid' => $item['plane']->getUuid()->toRfc4122(),
                'message' => (string) $item['plane']->getMessage(),
                'createdAt' => $item['plane']->getCreatedAt()?->format(\DATE_ATOM),
                'viewCount' => $item['viewCount'],
                'stampCount' => \count($item['stamps']),
                'stamps' => array_map(fn (PlaneVisit $stamp): array => [
                    'uuid' => $stamp->getUuid()->toRfc4122(),
                    'countryCode' => $stamp->getCountryCode(),
                    'color' => $this->stampColor->colorFor($stamp->getUuid()),
                ], $item['stamps']),
            ], $items),
        ];
    }
}
