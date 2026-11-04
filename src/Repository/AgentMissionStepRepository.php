<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentMissionStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentMissionStep>
 */
class AgentMissionStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentMissionStep::class);
    }
}
