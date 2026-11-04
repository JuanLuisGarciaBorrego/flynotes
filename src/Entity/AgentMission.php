<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\Tool\PublishSkyReport;
use App\Doctrine\TimestampTrait;
use App\Enum\AgentMissionStatus;
use App\Enum\AgentMissionStepType;
use App\Repository\AgentMissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A Sky Pulse mission, shared between visitors and preserved as public history.
 */
#[ORM\Entity(repositoryClass: AgentMissionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class AgentMission
{
    use TimestampTrait;

    public const int TITLE_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 24, enumType: AgentMissionStatus::class)]
    private AgentMissionStatus $status = AgentMissionStatus::Running;

    /**
     * Mission title selected by the agent.
     */
    #[ORM\Column(length: self::TITLE_MAX_LENGTH, nullable: true)]
    private ?string $title = null;

    /**
     * Paper plane message proposed by the agent.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $proposedMessage = null;

    /**
     * Paper plane published after human approval.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?PaperPlane $publishedPlane = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $inputTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $outputTokens = 0;

    /** Id of the research tool call waiting for a human, which ties the authorization to that very call. */
    #[ORM\Column(nullable: true)]
    private ?string $pendingToolCallId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pendingToolName = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pendingToolArguments = null;

    /** Set by a human's approval and cleared when the tool has run: an authorization is used once. */
    #[ORM\Column(options: ['default' => false])]
    private bool $pendingToolApproved = false;

    /**
     * Agent conversation up to the pending tool call (a normalized MessageBag), to continue it in a later request.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $agentContext = null;

    /**
     * @var Collection<int, AgentMissionStep>
     */
    #[ORM\OneToMany(
        targetEntity: AgentMissionStep::class,
        mappedBy: 'mission',
        cascade: ['persist'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getStatus(): AgentMissionStatus
    {
        return $this->status;
    }

    /**
     * The agent asked for a research tool: the request is stored and the mission waits for a human.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $agentContext
     */
    public function requestToolApproval(string $toolCallId, string $toolName, array $arguments, array $agentContext): void
    {
        $this->pendingToolCallId = $toolCallId;
        $this->pendingToolName = $toolName;
        $this->pendingToolArguments = $arguments;
        $this->agentContext = $agentContext;
        $this->addStep(AgentMissionStepType::ApprovalRequested, $toolName);
        $this->status = AgentMissionStatus::WaitingToolApproval;
    }

    /** Why a human cannot decide on the pending tool now, as shown to them; null when they can. */
    public function getToolDecisionConflict(): ?string
    {
        return match ($this->status) {
            AgentMissionStatus::WaitingToolApproval => null === $this->pendingToolName || $this->pendingToolApproved
                ? 'This mission is no longer available for approval.'
                : null,
            AgentMissionStatus::Running, AgentMissionStatus::WaitingApproval => 'This tool has already been decided.',
            AgentMissionStatus::Completed => 'This mission has already been approved.',
            AgentMissionStatus::Rejected => 'This mission has already been rejected.',
            AgentMissionStatus::Failed => 'This mission is no longer available for approval.',
        };
    }

    /** A human authorized the pending tool: back to Running, so nobody else can decide on it. */
    public function beginApprovedTool(): void
    {
        $this->pendingToolApproved = true;
        $this->addStep(AgentMissionStepType::Approved, $this->pendingToolName);
        $this->status = AgentMissionStatus::Running;
    }

    /** @param mixed $result what the tool returned: the evidence a human reviews */
    public function finishApprovedTool(mixed $result): void
    {
        $this->addStep(AgentMissionStepType::ToolSucceeded, $this->pendingToolName, ['result' => $result]);
        $this->clearPendingTool();
    }

    public function rejectToolApproval(): void
    {
        $this->addStep(AgentMissionStepType::Rejected, $this->pendingToolName);
        $this->clearPendingTool();
        $this->status = AgentMissionStatus::Rejected;
    }

    private function clearPendingTool(): void
    {
        $this->pendingToolCallId = null;
        $this->pendingToolName = null;
        $this->pendingToolArguments = null;
        $this->pendingToolApproved = false;
        $this->agentContext = null;
    }

    public function getPendingToolCallId(): ?string
    {
        return $this->pendingToolCallId;
    }

    public function getPendingToolName(): ?string
    {
        return $this->pendingToolName;
    }

    /** @return array<string, mixed>|null */
    public function getPendingToolArguments(): ?array
    {
        return $this->pendingToolArguments;
    }

    /** @return array<string, mixed>|null */
    public function getAgentContext(): ?array
    {
        return $this->agentContext;
    }

    /** The agent asked to publish: the request is stored and the mission waits for a human. */
    public function requestApproval(string $title, string $proposedMessage): void
    {
        $this->title = $title;
        $this->proposedMessage = $proposedMessage;
        $this->addStep(AgentMissionStepType::ProposalCreated);
        $this->addStep(AgentMissionStepType::ApprovalRequested, PublishSkyReport::NAME);
        $this->status = AgentMissionStatus::WaitingApproval;
    }

    /** An authorized tool still pending here is one that did not finish. */
    public function fail(): void
    {
        if ($this->pendingToolApproved) {
            $this->addStep(AgentMissionStepType::ToolFailed, $this->pendingToolName);
        }

        $this->addStep(AgentMissionStepType::Failed);
        $this->clearPendingTool();
        $this->status = AgentMissionStatus::Failed;
    }

    /** Why a human cannot decide on this mission now, as shown to them; null when they can. */
    public function getDecisionConflict(): ?string
    {
        return match ($this->status) {
            AgentMissionStatus::WaitingApproval => null === $this->proposedMessage || null !== $this->publishedPlane
                ? 'This mission is no longer available for approval.'
                : null,
            AgentMissionStatus::Completed => 'This mission has already been approved.',
            AgentMissionStatus::Rejected => 'This mission has already been rejected.',
            AgentMissionStatus::Running, AgentMissionStatus::WaitingToolApproval => 'This mission is not ready for approval yet.',
            AgentMissionStatus::Failed => 'This mission is no longer available for approval.',
        };
    }

    public function approve(PaperPlane $plane): void
    {
        $this->publishedPlane = $plane;
        $this->addStep(AgentMissionStepType::Approved, PublishSkyReport::NAME);
        $this->addStep(AgentMissionStepType::Completed);
        $this->status = AgentMissionStatus::Completed;
    }

    public function reject(): void
    {
        $this->addStep(AgentMissionStepType::Rejected, PublishSkyReport::NAME);
        $this->status = AgentMissionStatus::Rejected;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getProposedMessage(): ?string
    {
        return $this->proposedMessage;
    }

    public function getPublishedPlane(): ?PaperPlane
    {
        return $this->publishedPlane;
    }

    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function addTokenUsage(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
    }

    /**
     * @return Collection<int, AgentMissionStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function addStep(AgentMissionStepType $type, ?string $toolName = null, ?array $payload = null): void
    {
        $this->steps->add(new AgentMissionStep($this, $this->steps->count() + 1, $type, $toolName, $payload));
    }
}
