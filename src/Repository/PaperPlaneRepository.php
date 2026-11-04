<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\PlaneVisitType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaperPlane>
 */
class PaperPlaneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaperPlane::class);
    }

    /** @return list<PaperPlane> */
    public function findRandom(int $limit): array
    {
        // DQL has no RANDOM(): pick ids with PostgreSQL, then load the entities.
        $table = $this->getClassMetadata()->getTableName();
        $ids = $this->getEntityManager()->getConnection()
            ->executeQuery(sprintf('SELECT id FROM %s ORDER BY RANDOM() LIMIT :limit', $table), ['limit' => $limit], ['limit' => ParameterType::INTEGER])
            ->fetchFirstColumn();

        if ([] === $ids) {
            return [];
        }

        $planes = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.createdBy', 'c')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // The IN query returns rows in storage order: shuffle so the order is random too.
        shuffle($planes);

        return $planes;
    }

    /**
     * One page of the participant's own planes, newest first, each with its view count (no N+1):
     * limited in SQL, plus the total number of the author's planes.
     *
     * @return array{rows: list<array{plane: PaperPlane, views: int}>, total: int}
     */
    public function paginateByAuthorWithCounts(Participant $author, int $offset, int $limit): array
    {
        // No collection is fetch-joined, so a plain LIMIT/OFFSET is exact; the total is a separate COUNT query.
        $page = new OffsetPaginator(fetchJoinCollection: false)
            ->paginate($this->byAuthorWithCounts($author), new Window($offset, $limit));

        return ['rows' => self::toRows($page->getItems()), 'total' => $page->getTotalCount()];
    }

    /**
     * Totals over all the author's planes, aggregated in one query:
     * - planes: planes published;
     * - views: reads by others (PlaneVisitType::View visits; repeated and anonymous reads count, not unique readers);
     * - countries: distinct countries that stamped them (participations with a country).
     *
     * @return array{planes: int, views: int, countries: int}
     */
    public function statsByAuthor(Participant $author): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id) AS planes')
            ->addSelect('COALESCE(SUM(CASE WHEN v.type = :view THEN 1 ELSE 0 END), 0) AS views')
            // DQL has no bare NULL: NULLIF('', '') is a text NULL, which COUNT ignores.
            ->addSelect("COUNT(DISTINCT CASE WHEN v.type = :participation THEN v.countryCode ELSE NULLIF('', '') END) AS countries")
            ->leftJoin(PlaneVisit::class, 'v', Join::WITH, 'v.plane = p')
            ->where('p.createdBy = :author')
            ->setParameter('author', $author)
            ->setParameter('view', PlaneVisitType::View)
            ->setParameter('participation', PlaneVisitType::Participation)
            ->getQuery()
            ->getSingleResult();

        return ['planes' => (int) $row['planes'], 'views' => (int) $row['views'], 'countries' => (int) $row['countries']];
    }

    private function byAuthorWithCounts(Participant $author): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->select('p AS plane')
            ->addSelect(sprintf('(SELECT COUNT(v.id) FROM %s v WHERE v.plane = p AND v.type = :view) AS views', PlaneVisit::class))
            ->where('p.createdBy = :author')
            ->setParameter('author', $author)
            ->setParameter('view', PlaneVisitType::View)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
    }

    /**
     * @param list<array{plane: PaperPlane, views: int|string}> $rows
     *
     * @return list<array{plane: PaperPlane, views: int}>
     */
    private static function toRows(array $rows): array
    {
        return array_map(static fn (array $row): array => ['plane' => $row['plane'], 'views' => (int) $row['views']], $rows);
    }
}
