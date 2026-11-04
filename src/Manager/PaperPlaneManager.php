<?php

declare(strict_types=1);

namespace App\Manager;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\PaperPlaneType;
use App\Repository\PaperPlaneRepository;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Paper plane use cases shared by every entry channel (API Platform over HTTP, MCP tools). Returns entities
 * and plain data, never channel representations; who the caller is comes in as an argument.
 */
readonly class PaperPlaneManager
{
    /** Size of the random feed; not client-configurable. */
    public const int RANDOM_LIMIT = 30;

    /** Page size of an author's own planes ("My planes"); not client-configurable. */
    public const int OWN_PER_PAGE = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaneVisitManager $planeVisitManager,
        private PaperPlaneRepository $paperPlaneRepository,
        private PlaneVisitRepository $planeVisitRepository,
    ) {
    }

    /** The message must come validated by the entry layer (API resource: NotBlank + Length). */
    public function create(Participant $participant, string $message): PaperPlane
    {
        $message = trim($message);

        $paperPlane = new PaperPlane()
            ->setCreatedBy($participant)
            ->setType(PaperPlaneType::Custom)
            ->setMessage($message);

        $this->entityManager->persist($paperPlane);
        $this->entityManager->flush();

        return $paperPlane;
    }

    /**
     * Up to RANDOM_LIMIT random planes, in random order (authors fetched with them). Listing records no visits.
     *
     * @return list<PaperPlane>
     */
    public function getRandom(): array
    {
        return $this->paperPlaneRepository->findRandom(self::RANDOM_LIMIT);
    }

    /**
     * Finds a paper plane by UUID without recording a visit.
     */
    public function getByUuid(Uuid $uuid): ?PaperPlane
    {
        return $this->paperPlaneRepository->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Opens one plane for a viewer (null = anonymous) and returns what its detail needs; null when no plane
     * has that UUID. The read is recorded as a visit, except when the author opens their own plane or when the
     * channel says it is not a real read ($recordView = false, e.g. HTTP HEAD or a browser prefetch).
     * `viewCount` already includes the visit just recorded.
     *
     * Viewer flags are false for anonymous viewers. `canStamp` mirrors PlaneVisitManager::registerParticipation():
     * any participant (human or bot) with a country, never on their own plane nor twice.
     *
     * @return array{plane: PaperPlane, viewCount: int, stamps: list<PlaneVisit>, isAuthor: bool, hasStamped: bool, canStamp: bool}|null
     */
    public function show(Uuid $uuid, ?Participant $viewer, bool $recordView = true): ?array
    {
        $plane = $this->getByUuid($uuid);

        if (null === $plane) {
            return null;
        }

        $isAuthor = null !== $viewer && $plane->getCreatedBy()?->getId() === $viewer->getId();

        if ($recordView && !$isAuthor) {
            $this->planeVisitManager->registerView($plane, $viewer);
        }

        $hasStamped = null !== $viewer && !$isAuthor && $this->planeVisitRepository->hasParticipation($plane, $viewer);

        return [
            'plane' => $plane,
            'viewCount' => $this->planeVisitRepository->countViews($plane),
            'stamps' => $this->planeVisitRepository->findParticipations($plane),
            'isAuthor' => $isAuthor,
            'hasStamped' => $hasStamped,
            'canStamp' => null !== $viewer
                && null !== $viewer->getCountryCode()
                && !$isAuthor
                && !$hasStamped,
        ];
    }

    /**
     * One page (1-based, OWN_PER_PAGE planes) of the author's own planes, newest first, plus the total number of
     * their planes. Each plane comes with its view count and its stamps (oldest first), in three queries whatever
     * the number of planes. Listing records no visits.
     *
     * @return array{items: list<array{plane: PaperPlane, viewCount: int, stamps: list<PlaneVisit>}>, total: int}
     */
    public function getOwn(Participant $author, int $page): array
    {
        $page = max(1, $page);
        ['rows' => $rows, 'total' => $total] = $this->paperPlaneRepository->paginateByAuthorWithCounts(
            $author,
            ($page - 1) * self::OWN_PER_PAGE,
            self::OWN_PER_PAGE,
        );

        $stampsByPlane = [];
        foreach ($this->planeVisitRepository->findParticipationsForPlanes(array_column($rows, 'plane')) as $stamp) {
            $stampsByPlane[$stamp->getPlane()->getId()][] = $stamp;
        }

        return [
            'items' => array_map(static fn (array $row): array => [
                'plane' => $row['plane'],
                'viewCount' => $row['views'],
                'stamps' => $stampsByPlane[$row['plane']->getId()] ?? [],
            ], $rows),
            'total' => $total,
        ];
    }

    /**
     * Totals over all the author's planes, whatever page is shown: planes published, reads by others (every read
     * counts, also repeated and anonymous ones) and distinct countries that stamped them (stamps without a country
     * do not count). One aggregated query; records no visits.
     *
     * @return array{planes: int, views: int, countries: int}
     */
    public function getOwnStats(Participant $author): array
    {
        return $this->paperPlaneRepository->statsByAuthor($author);
    }
}
