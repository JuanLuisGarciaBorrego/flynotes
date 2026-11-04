<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentMission;
use App\Enum\AgentMissionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentMission>
 */
class AgentMissionRepository extends ServiceEntityRepository
{
    private const array ACTIVE_STATUSES = [AgentMissionStatus::Running, AgentMissionStatus::WaitingToolApproval, AgentMissionStatus::WaitingApproval];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentMission::class);
    }

    public function hasActiveMission(): bool
    {
        return $this->count(['status' => self::ACTIVE_STATUSES]) > 0;
    }

    /**
     * The mission a visitor should see: the active one, which is what blocks starting another, or else the last one.
     * Active first because two simultaneous starts can leave an older mission active behind a newer finished one.
     */
    public function findCurrent(): ?AgentMission
    {
        return $this->findOneBy(['status' => self::ACTIVE_STATUSES], ['id' => 'DESC'])
            ?? $this->findOneBy([], ['id' => 'DESC']);
    }

    /** Whether a human authorized this very tool call and it has not been executed yet. */
    public function isToolCallApproved(string $toolCallId, string $toolName): bool
    {
        return $this->count([
            'status' => AgentMissionStatus::Running,
            'pendingToolCallId' => $toolCallId,
            'pendingToolName' => $toolName,
            'pendingToolApproved' => true,
        ]) > 0;
    }
}
