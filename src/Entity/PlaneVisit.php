<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\CreatedAtTrait;
use App\Enum\PlaneVisitType;
use App\Repository\PlaneVisitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlaneVisitRepository::class)]
#[ORM\Index(name: 'idx_plane_visit_plane_created_at', columns: ['plane_id', 'created_at'])]
#[ORM\Index(name: 'idx_plane_visit_participant_created_at', columns: ['participant_id', 'created_at'])]
// One stamp (Participation) per participant and plane; Views are unrestricted. Also guards concurrent requests.
// The WHERE is written as PostgreSQL normalizes it, so schema comparison finds no false drift.
#[ORM\UniqueConstraint(name: 'uniq_plane_visit_participation', columns: ['plane_id', 'participant_id'], options: ['where' => "((type)::text = 'participation'::text)"])]
#[ORM\HasLifecycleCallbacks]
class PlaneVisit
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?PaperPlane $plane = null;

    /** Null for anonymous visitors. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Participant $participant = null;

    #[ORM\Column(length: 16, nullable: false, enumType: PlaneVisitType::class)]
    private ?PlaneVisitType $type = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $languageCode = null;

    /** Country the participant had when stamping (ISO 3166-1 alpha-2), kept even if they change it later. Null for views. */
    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country]
    private ?string $countryCode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $emoji = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getPlane(): ?PaperPlane
    {
        return $this->plane;
    }

    public function setPlane(PaperPlane $plane): static
    {
        $this->plane = $plane;

        return $this;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): static
    {
        $this->participant = $participant;

        return $this;
    }

    public function getType(): ?PlaneVisitType
    {
        return $this->type;
    }

    public function setType(PlaneVisitType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLanguageCode(): ?string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(?string $languageCode): static
    {
        $this->languageCode = $languageCode;

        return $this;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): static
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function getEmoji(): ?string
    {
        return $this->emoji;
    }

    public function setEmoji(?string $emoji): static
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }
}
