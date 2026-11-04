<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\CreatedAtTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One AI reinterpretation generated for a plane (human in the loop). Every generation is kept, also the discarded
 * ones; the plane points to the approved one, if any. Created only by PaperPlane::proposeAiMessage().
 */
#[ORM\Entity]
// One row per generation number: also guards against two concurrent generations taking the same slot.
#[ORM\UniqueConstraint(name: 'uniq_ai_message_version_plane_number', columns: ['plane_id', 'number'])]
#[ORM\HasLifecycleCallbacks]
class AiMessageVersion
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Public id: the client sends it back to approve exactly the version it showed. */
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'aiVersions')]
        #[ORM\JoinColumn(nullable: false)]
        private PaperPlane $plane,
        /** 1 = initial proposal, 2 and 3 = retries. */
        #[ORM\Column(type: Types::SMALLINT, updatable: false)]
        private int $number,
        #[ORM\Column(type: Types::TEXT, updatable: false)]
        private string $text,
        /** Tone the generator gave the text (shown as a chip); null if it gave none. */
        #[ORM\Column(length: 64, nullable: true, updatable: false)]
        private ?string $tone,
    ) {
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

    public function getPlane(): PaperPlane
    {
        return $this->plane;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getTone(): ?string
    {
        return $this->tone;
    }
}
