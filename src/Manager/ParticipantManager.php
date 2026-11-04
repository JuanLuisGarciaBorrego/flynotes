<?php

declare(strict_types=1);

namespace App\Manager;

use App\Entity\Participant;
use App\Enum\EmojiPersonality;
use App\Enum\ParticipantType;
use App\Helper\ParticipantCodeGeneratorHelper;
use App\Repository\ParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;

class ParticipantManager
{
    private const int CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly ParticipantRepository $participantRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ParticipantCodeGeneratorHelper $codeGenerator,
    ) {
    }

    /**
     * Creates and persists a participant.
     *
     * This method only normalizes the name; it does NOT validate it. HTTP requests are
     * validated by API Platform, but internal callers (e.g. future MCP tools) must validate
     * the input themselves (Participant::NAME_MAX_LENGTH, non-blank) with the Validator.
     */
    public function create(
        ParticipantType $type,
        string $name,
        ?string $countryCode = null,
    ): Participant {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        $participant = new Participant()
            ->setType($type)
            ->setName($name)
            ->setCode($this->generateUniqueCode())
            ->setEmoji(EmojiPersonality::random()->value)
            ->setCountryCode(null !== $countryCode ? strtoupper(trim($countryCode)) : null);

        // The UNIQUE constraint on `code` remains the final guarantee against races.
        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
    }

    /** The author of the reports Sky Pulse publishes, created the first time one is approved. */
    public function getSkyPulse(): Participant
    {
        $skyPulse = $this->participantRepository->findOneBy(['code' => Participant::SKY_PULSE_CODE]);
        if (null !== $skyPulse) {
            return $skyPulse;
        }

        $skyPulse = $this->create(ParticipantType::Bot, 'Sky Pulse')->setCode(Participant::SKY_PULSE_CODE);
        $this->entityManager->flush();

        return $skyPulse;
    }

    private function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; ++$attempt) {
            $code = $this->codeGenerator->generate();
            if (null === $this->participantRepository->findOneBy(['code' => $code])) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique participant code.');
    }

    public function findByCode(string $code): ?Participant
    {
        $canonical = $this->codeGenerator->normalize($code);
        if (null === $canonical) {
            return null;
        }

        return $this->participantRepository->findOneBy(['code' => $canonical]);
    }
}
