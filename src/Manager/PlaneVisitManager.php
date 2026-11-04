<?php

declare(strict_types=1);

namespace App\Manager;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Entity\PlaneVisit;
use App\Enum\PlaneVisitType;
use App\Exception\AlreadyStampedException;
use App\Exception\StampNotAllowedException;
use App\Repository\PlaneVisitRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records visits to paper planes. Channel-agnostic (HTTP today, MCP later) and open to
 * any participant type; authentication and authorization belong to the entry layers.
 */
readonly class PlaneVisitManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaneVisitRepository $planeVisitRepository,
    ) {
    }

    /**
     * Records that the participant (null = anonymous) opened the plane. Every call stores a new visit
     * (opening history). Self-visits are filtered by the entry layers.
     *
     * The participant has no language yet, so `languageCode` stays null for now.
     */
    public function registerView(PaperPlane $plane, ?Participant $participant): PlaneVisit
    {
        $visit = new PlaneVisit()
            ->setPlane($plane)
            ->setParticipant($participant)
            ->setType(PlaneVisitType::View)
            ->setEmoji(null)
            ->setMessage(null);

        $this->entityManager->persist($visit);
        $this->entityManager->flush();

        return $visit;
    }

    /**
     * Stamps the participant's country on the plane's passport (a `Participation` visit).
     * Country and emoji are copied from the participant now, so the stamp does not change if they edit their profile.
     * Open to humans and bots alike; each entry layer only decides who is authenticated. The rules are
     * enforced here, and PaperPlaneManager::show() reports them as `canStamp`.
     *
     * @throws StampNotAllowedException when the participant authored the plane or has no country
     * @throws AlreadyStampedException  when the participant already stamped this plane (also under concurrent requests)
     */
    public function registerParticipation(PaperPlane $plane, Participant $participant): PlaneVisit
    {
        if ($plane->getCreatedBy()?->getId() === $participant->getId()) {
            throw new StampNotAllowedException('You cannot stamp your own plane.');
        }
        if (null === $participant->getCountryCode()) {
            throw new StampNotAllowedException('A country is required to stamp a plane.');
        }
        if ($this->planeVisitRepository->hasParticipation($plane, $participant)) {
            throw new AlreadyStampedException('This plane is already stamped by the participant.');
        }

        $visit = new PlaneVisit()
            ->setPlane($plane)
            ->setParticipant($participant)
            ->setType(PlaneVisitType::Participation)
            ->setCountryCode($participant->getCountryCode())
            ->setEmoji($participant->getEmoji())
            ->setMessage(null);

        try {
            $this->entityManager->persist($visit);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request won the race: the partial unique index is the final guard.
            throw new AlreadyStampedException('This plane is already stamped by the participant.', previous: $e);
        }

        return $visit;
    }
}
