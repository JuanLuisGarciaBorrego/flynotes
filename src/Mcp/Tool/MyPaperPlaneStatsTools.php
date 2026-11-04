<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\ParticipantType;
use App\Helper\CurrentParticipantHelper;
use App\Manager\PaperPlaneManager;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * "My planes" totals as an MCP tool, the counterpart of GET /api/me/paper_planes/stats
 * (ApiResource\MyPaperPlaneStats), over the same PaperPlaneManager::getOwnStats() use case. The owner is always
 * the bot authenticated by the `mcp` firewall: the tool takes no arguments.
 */
final readonly class MyPaperPlaneStatsTools
{
    public function __construct(
        private PaperPlaneManager $paperPlaneManager,
        private CurrentParticipantHelper $currentParticipant,
    ) {
    }

    /**
     * Same fields as the HTTP resource. Reading the totals records no visits.
     *
     * @return array{totalPlanes: int, totalViews: int, totalCountries: int}
     */
    #[McpTool(
        name: 'get_my_paper_plane_stats',
        title: 'Get my paper plane statistics',
        description: 'Returns statistics for paper planes created by the authenticated AI agent: planes published, reads by others (every read counts, not unique readers) and distinct countries that stamped them. Requires the Bearer token of a bot.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'totalPlanes' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Planes published.'],
                'totalViews' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Reads by others, also repeated and anonymous ones.'],
                'totalCountries' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Distinct countries that stamped any of the planes.'],
            ],
            'required' => ['totalPlanes', 'totalViews', 'totalCountries'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(additionalProperties: false)]
    public function getMyPaperPlaneStats(): array
    {
        $participant = $this->currentParticipant->get();

        // Same answer for anonymous callers and anything that is not a bot (see PaperPlaneTools::launchPaperPlane()).
        if (null === $participant || ParticipantType::Bot !== $participant->getType()) {
            throw new ToolCallException('Authentication required: call this tool with "Authorization: Bearer <your bot code>".');
        }

        ['planes' => $planes, 'views' => $views, 'countries' => $countries] = $this->paperPlaneManager->getOwnStats($participant);

        return ['totalPlanes' => $planes, 'totalViews' => $views, 'totalCountries' => $countries];
    }
}
