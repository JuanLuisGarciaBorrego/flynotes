<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\PlaneVisitType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaneVisit>
 */
class PlaneVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaneVisit::class);
    }

    public function hasParticipation(PaperPlane $plane, Participant $participant): bool
    {
        return null !== $this->createQueryBuilder('v')
            ->select('v.id')
            ->where('v.plane = :plane AND v.participant = :participant AND v.type = :type')
            ->setParameter('plane', $plane)
            ->setParameter('participant', $participant)
            ->setParameter('type', PlaneVisitType::Participation)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countViews(PaperPlane $plane): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.plane = :plane AND v.type = :type')
            ->setParameter('plane', $plane)
            ->setParameter('type', PlaneVisitType::View)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Reads recorded across all planes (repeated and anonymous reads count, not unique readers). */
    public function countViewsTotal(): int
    {
        return $this->count(['type' => PlaneVisitType::View]);
    }

    /** Participation stamps across all planes. */
    public function countStampsTotal(): int
    {
        return $this->count(['type' => PlaneVisitType::Participation]);
    }

    /**
     * The plane's passport, oldest stamp first. Stamps carry their own country and emoji,
     * so no participant is loaded (no N+1, nothing private exposed).
     *
     * @return list<PlaneVisit>
     */
    public function findParticipations(PaperPlane $plane): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.plane = :plane AND v.type = :type')
            ->setParameter('plane', $plane)
            ->setParameter('type', PlaneVisitType::Participation)
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The stamps of several planes in one query, oldest first (for lists of planes; no participant is loaded).
     *
     * @param list<PaperPlane> $planes
     *
     * @return list<PlaneVisit>
     */
    public function findParticipationsForPlanes(array $planes): array
    {
        if ([] === $planes) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->where('v.plane IN (:planes) AND v.type = :type')
            ->setParameter('planes', $planes)
            ->setParameter('type', PlaneVisitType::Participation)
            ->orderBy('v.createdAt', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
