<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AgentMissionStepType;
use App\Repository\AgentMissionStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AgentMissionStepRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_agent_mission_step_sequence',
    columns: ['mission_id', 'sequence']
)]
class AgentMissionStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentMission $mission;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 32, enumType: AgentMissionStepType::class)]
    private AgentMissionStepType $type;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $toolName = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        AgentMission $mission,
        int $sequence,
        AgentMissionStepType $type,
        ?string $toolName = null,
        ?array $payload = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->mission = $mission;
        $this->sequence = $sequence;
        $this->type = $type;
        $this->toolName = $toolName;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getType(): AgentMissionStepType
    {
        return $this->type;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
