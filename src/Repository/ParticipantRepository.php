<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Participant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Participant>
 */
class ParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    /**
     * Returns aggregated emoji usage among registered participants.
     *
     * @return list<array{emoji: string, total: int}>
     */
    public function countByEmoji(): array
    {
        $results = $this->createQueryBuilder('p')
            ->select('p.emoji AS emoji', 'COUNT(p.id) AS total')
            ->where('p.emoji IS NOT NULL')
            ->groupBy('p.emoji')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'emoji' => $row['emoji'],
            'total' => (int) $row['total'],
        ], $results);
    }

    /**
     * Returns aggregated participant counts by country.
     *
     * @return list<array{countryCode: string, total: int}>
     */
    public function countByCountry(): array
    {
        $results = $this->createQueryBuilder('p')
            ->select('p.countryCode AS countryCode', 'COUNT(p.id) AS total')
            ->where('p.countryCode IS NOT NULL')
            ->groupBy('p.countryCode')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'countryCode' => $row['countryCode'],
            'total' => (int) $row['total'],
        ], $results);
    }
}
