<?php

declare(strict_types=1);

namespace App\State\MyPaperPlane;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MyPaperPlaneStats;
use App\Helper\CurrentParticipantHelper;
use App\Manager\PaperPlaneManager;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * GET /me/paper_planes/stats: the session participant's totals (PaperPlaneManager::getOwnStats()).
 * Read-only: it never registers a visit.
 *
 * @implements ProviderInterface<MyPaperPlaneStats>
 */
final readonly class MyPaperPlaneStatsProvider implements ProviderInterface
{
    public function __construct(
        private CurrentParticipantHelper $currentParticipant,
        private PaperPlaneManager $paperPlaneManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyPaperPlaneStats
    {
        // The operation's security already requires ROLE_HUMAN; this only guards against a misconfiguration.
        $author = $this->currentParticipant->get() ?? throw new AccessDeniedException();

        ['planes' => $planes, 'views' => $views, 'countries' => $countries] = $this->paperPlaneManager->getOwnStats($author);

        return new MyPaperPlaneStats($planes, $views, $countries);
    }
}
