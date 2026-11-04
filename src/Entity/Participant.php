<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampTrait;
use App\Enum\EmojiPersonality;
use App\Enum\ParticipantType;
use App\Repository\ParticipantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Participant implements UserInterface
{
    use TimestampTrait;
    /**
     * Identifies the Sky Pulse agent, unlike its name, which any bot can register. It is not a credential:
     * outside the code alphabet, so it is never generated and ParticipantCodeGeneratorHelper::normalize() rejects it.
     */
    public const string SKY_PULSE_CODE = 'sky-pulse';

    public const int NAME_MAX_LENGTH = 255;
    public const int COUNTRY_CODE_LENGTH = 2;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 16, nullable: false, enumType: ParticipantType::class)]
    private ?ParticipantType $type = null;

    /** Secret credential (plain text by MVP decision). Never expose or log it. */
    #[ORM\Column(length: 32, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: self::NAME_MAX_LENGTH, nullable: false)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: self::NAME_MAX_LENGTH)]
    private ?string $name = null;

    #[ORM\Column(length: self::COUNTRY_CODE_LENGTH, nullable: true)]
    #[Assert\Country]
    private ?string $countryCode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $emoji = null;

    public function __construct()
    {
        $this->uuid = Uuid::v7();
    }

    /**
     * The security token (session storage) only keeps the identifiers; the user is reloaded from the
     * database on each request. This keeps the recovery code out of the session data.
     *
     * @return array{id: ?int, uuid: Uuid}
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'uuid' => $this->uuid];
    }

    /** @param array{id: ?int, uuid: Uuid} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->uuid = $data['uuid'];
    }

    public function getUserIdentifier(): string
    {
        return $this->uuid->toRfc4122();
    }

    /** @return list<string> ROLE_HUMAN for people on the web, ROLE_MCP for bots */
    public function getRoles(): array
    {
        return [ParticipantType::Bot === $this->type ? 'ROLE_MCP' : 'ROLE_HUMAN'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getType(): ?ParticipantType
    {
        return $this->type;
    }

    public function setType(ParticipantType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isSkyPulse(): bool
    {
        return self::SKY_PULSE_CODE === $this->code;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    /**
     * The fictional personality behind the emoji (see EmojiPersonality). Null for an emoji outside
     * the current set, e.g. an animal kept by a database that has not run the emoji migration.
     */
    public function getPersonality(): ?EmojiPersonality
    {
        return null === $this->emoji ? null : EmojiPersonality::tryFrom($this->emoji);
    }
}
