<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampTrait;
use App\Enum\AiMessageStatus;
use App\Enum\PaperPlaneType;
use App\Exception\AiMessageStateException;
use App\Repository\PaperPlaneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaperPlaneRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_paper_plane_welcome_per_participant',
    columns: ['created_by_id'],
    options: ['where' => "((type)::text = 'welcome'::text)"],
)]
#[ORM\HasLifecycleCallbacks]
class PaperPlane
{
    use TimestampTrait;

    public const int MESSAGE_MIN_LENGTH = 15;
    public const int MESSAGE_MAX_LENGTH = 250;
    public const int AI_MESSAGE_MAX_LENGTH = 250;
    /** Total AI generations per plane: the initial proposal plus two retries. */
    public const int AI_MAX_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Participant $createdBy = null;

    #[ORM\Column(length: 16, nullable: false, enumType: PaperPlaneType::class)]
    private ?PaperPlaneType $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: false, updatable: false)]
    private ?string $message = null;

    /**
     * Every AI reinterpretation generated for this plane, oldest first (also the discarded ones). The visible
     * proposal while pending is the last one.
     *
     * @var Collection<int, AiMessageVersion>
     */
    #[ORM\OneToMany(targetEntity: AiMessageVersion::class, mappedBy: 'plane', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $aiVersions;

    /** The version the author approved: the only AI text ever published. Null unless Accepted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?AiMessageVersion $approvedAiVersion = null;

    /**
     * AI generations performed so far (0 to AI_MAX_ATTEMPTS), equal to the number of versions. Kept as a column so the
     * limit and the concurrency check need no collection load. Only ever incremented, by proposeAiMessage().
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $aiAttempts = 0;

    /** Null while no proposal was ever generated (planes without AI). */
    #[ORM\Column(length: 16, nullable: true, enumType: AiMessageStatus::class)]
    private ?AiMessageStatus $aiStatus = null;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
        $this->aiVersions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getCreatedBy(): ?Participant
    {
        return $this->createdBy;
    }

    public function setCreatedBy(Participant $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getType(): ?PaperPlaneType
    {
        return $this->type;
    }

    public function setType(PaperPlaneType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        if (null !== $this->message) {
            throw new \LogicException('The message cannot be modified.');
        }

        $this->message = $message;

        return $this;
    }

    /** The published AI text: only the approved version's. */
    public function getAiMessage(): ?string
    {
        return $this->approvedAiVersion?->getText();
    }

    public function getApprovedAiVersion(): ?AiMessageVersion
    {
        return $this->approvedAiVersion;
    }

    /** The proposal on screen while pending (the latest generation); null if none was generated. */
    public function getCurrentAiVersion(): ?AiMessageVersion
    {
        return $this->aiVersions->last() ?: null;
    }

    /** @return list<AiMessageVersion> oldest first */
    public function getAiVersions(): array
    {
        return array_values($this->aiVersions->toArray());
    }

    public function findAiVersion(Uuid $uuid): ?AiMessageVersion
    {
        return $this->aiVersions->findFirst(static fn (int $key, AiMessageVersion $version): bool => $version->getUuid()->equals($uuid));
    }

    public function getAiAttempts(): int
    {
        return $this->aiAttempts;
    }

    /** Generations still allowed; computed, never stored. */
    public function getAiAttemptsRemaining(): int
    {
        return max(0, self::AI_MAX_ATTEMPTS - $this->aiAttempts);
    }

    public function getAiStatus(): ?AiMessageStatus
    {
        return $this->aiStatus;
    }

    /**
     * A new proposal is allowed while undecided (never generated, or pending) and below the generation limit.
     *
     * @throws AiMessageStateException when the reinterpretation is already decided or no generations are left
     */
    public function assertCanProposeAiMessage(): void
    {
        if (null !== $this->aiStatus && AiMessageStatus::Pending !== $this->aiStatus) {
            throw new AiMessageStateException('The AI message has already been decided.');
        }
        if ($this->aiAttempts >= self::AI_MAX_ATTEMPTS) {
            throw new AiMessageStateException('No AI generations left for this plane.');
        }
    }

    /**
     * Records a freshly generated proposal as a new version (it becomes the visible one) and counts the generation.
     *
     * @throws AiMessageStateException when the reinterpretation is already decided or no generations are left
     */
    public function proposeAiMessage(string $text, ?string $tone = null): AiMessageVersion
    {
        $this->assertCanProposeAiMessage();

        ++$this->aiAttempts;
        $version = new AiMessageVersion($this, $this->aiAttempts, $text, $tone);
        $this->aiVersions->add($version);
        $this->aiStatus = AiMessageStatus::Pending;

        return $version;
    }

    /**
     * Approves the visible version, the latest, and publishes it. The earlier ones stay stored as history, and a new
     * generation replaces the one on screen, so an answer based on an old screen is refused instead of guessed.
     *
     * @throws AiMessageStateException when nothing is pending, it is already decided, or it is not this plane's latest version
     */
    public function acceptAiVersion(AiMessageVersion $version): static
    {
        $this->assertAiMessagePending();
        if (!$this->aiVersions->contains($version)) {
            throw new AiMessageStateException('This AI version does not belong to the plane.');
        }
        if ($version !== $this->getCurrentAiVersion()) {
            throw new AiMessageStateException('This AI version has been replaced by a newer one.');
        }
        $this->approvedAiVersion = $version;
        $this->aiStatus = AiMessageStatus::Accepted;

        return $this;
    }

    /**
     * Rejects every version: none is published and the plane keeps only its original message. The versions stay
     * stored as history.
     *
     * @throws AiMessageStateException when there is no pending proposal (none generated, or already decided)
     */
    public function rejectAiMessage(): static
    {
        $this->assertAiMessagePending();
        $this->aiStatus = AiMessageStatus::Rejected;

        return $this;
    }

    private function assertAiMessagePending(): void
    {
        if (AiMessageStatus::Pending !== $this->aiStatus) {
            throw new AiMessageStateException(null === $this->aiStatus ? 'There is no AI message to decide on.' : 'The AI message has already been decided.');
        }
    }
}
