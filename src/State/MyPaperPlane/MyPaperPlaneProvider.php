<?php

declare(strict_types=1);

namespace App\State\MyPaperPlane;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MyPaperPlane;
use App\Entity\PlaneVisit;
use App\Helper\CurrentParticipantHelper;
use App\Helper\StampColorHelper;
use App\Manager\PaperPlaneManager;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * GET /me/paper_planes: one page of the session participant's own planes, newest first
 * (PaperPlaneManager::getOwn()), mapped to the resource. Read-only: it never registers a visit.
 *
 * @implements ProviderInterface<MyPaperPlane>
 */
final readonly class MyPaperPlaneProvider implements ProviderInterface
{
    public function __construct(
        private CurrentParticipantHelper $currentParticipant,
        private PaperPlaneManager $paperPlaneManager,
        private StampColorHelper $stampColor,
        private Pagination $pagination,
    ) {
    }

    /** @return TraversablePaginator<MyPaperPlane> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        // The operation's security already requires ROLE_HUMAN; this only guards against a misconfiguration.
        $author = $this->currentParticipant->get() ?? throw new AccessDeniedException();

        // Validates the `page` query parameter (400 below 1); the page size is the Manager's.
        $page = $this->pagination->getPage($context);
        ['items' => $items, 'total' => $total] = $this->paperPlaneManager->getOwn($author, $page);

        $resources = array_map(fn (array $item): MyPaperPlane => new MyPaperPlane(
            uuid: $item['plane']->getUuid()->toRfc4122(),
            message: (string) $item['plane']->getMessage(),
            createdAt: $item['plane']->getCreatedAt(),
            viewCount: $item['viewCount'],
            stampCount: \count($item['stamps']),
            stamps: array_map(fn (PlaneVisit $stamp): array => [
                'uuid' => $stamp->getUuid()->toRfc4122(),
                'countryCode' => $stamp->getCountryCode(),
                'color' => $this->stampColor->colorFor($stamp->getUuid()),
            ], $item['stamps']),
        ), $items);

        return new TraversablePaginator(new \ArrayIterator($resources), $page, PaperPlaneManager::OWN_PER_PAGE, $total);
    }
}
