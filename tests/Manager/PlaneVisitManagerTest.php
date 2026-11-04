<?php

declare(strict_types=1);

namespace App\Tests\Manager;

use App\Entity\PlaneVisit;
use App\Enum\ParticipantType;
use App\Enum\PlaneVisitType;
use App\Exception\AlreadyStampedException;
use App\Exception\StampNotAllowedException;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Manager\PlaneVisitManager;
use App\Repository\PlaneVisitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PlaneVisitManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    public function testRegisterViewStoresAViewVisitForAnyParticipantType(): void
    {
        $c = self::getContainer();
        $author = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autor');
        $bot = $c->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot');
        $plane = $c->get(PaperPlaneManager::class)->create($author, 'Hola');

        $visit = $c->get(PlaneVisitManager::class)->registerView($plane, $bot);

        self::assertNotNull($visit->getId());
        self::assertSame(PlaneVisitType::View, $visit->getType());
        self::assertSame($plane, $visit->getPlane());
        self::assertSame($bot, $visit->getParticipant());
        self::assertNotNull($visit->getCreatedAt());
        self::assertNull($visit->getEmoji());
        self::assertNull($visit->getMessage());
    }

    public function testReopeningKeepsHistory(): void
    {
        $c = self::getContainer();
        $author = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autor');
        $reader = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Lector');
        $plane = $c->get(PaperPlaneManager::class)->create($author, 'Hola');
        $manager = $c->get(PlaneVisitManager::class);

        $manager->registerView($plane, $reader);
        $manager->registerView($plane, $reader);

        self::assertCount(2, $this->em->getRepository(PlaneVisit::class)->findBy(['plane' => $plane]));
    }

    public function testAnonymousVisit(): void
    {
        $c = self::getContainer();
        $author = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autor');
        $plane = $c->get(PaperPlaneManager::class)->create($author, 'Hola');

        $visit = $c->get(PlaneVisitManager::class)->registerView($plane, null);

        self::assertNotNull($visit->getId());
        self::assertNull($visit->getParticipant());
    }

    /** @return array{0: \App\Entity\Participant, 1: \App\Entity\Participant, 2: \App\Entity\PaperPlane} */
    private function seedPlane(): array
    {
        $c = self::getContainer();
        $author = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autor', 'ES');
        $reader = $c->get(ParticipantManager::class)->create(ParticipantType::Human, 'Lector', 'JP');

        return [$author, $reader, $c->get(PaperPlaneManager::class)->create($author, 'Hola')];
    }

    public function testRegisterParticipationCopiesCountryAndEmojiFromTheParticipant(): void
    {
        [, $reader, $plane] = $this->seedPlane();

        $stamp = self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $reader);

        self::assertNotNull($stamp->getId());
        self::assertSame(PlaneVisitType::Participation, $stamp->getType());
        self::assertSame('JP', $stamp->getCountryCode());
        self::assertSame($reader->getEmoji(), $stamp->getEmoji());
        self::assertNull($stamp->getMessage());
        self::assertSame($reader, $stamp->getParticipant());
        self::assertSame($plane, $stamp->getPlane());
        self::assertNotNull($stamp->getCreatedAt());
    }

    public function testStampKeepsItsCountryWhenTheParticipantMovesAway(): void
    {
        [, $reader, $plane] = $this->seedPlane();
        $stamp = self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $reader);

        $reader->setCountryCode('FR');
        $this->em->flush();

        self::assertSame('JP', $stamp->getCountryCode());
    }

    public function testAuthorCannotStampOwnPlane(): void
    {
        [$author, , $plane] = $this->seedPlane();

        $this->expectException(StampNotAllowedException::class);
        self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $author);
    }

    public function testBotCanStampAPlaneByAnotherParticipant(): void
    {
        [, , $plane] = $this->seedPlane();
        $bot = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot', 'PT');

        $stamp = self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $bot);

        self::assertSame(PlaneVisitType::Participation, $stamp->getType());
        self::assertSame('PT', $stamp->getCountryCode());
        self::assertSame($bot->getEmoji(), $stamp->getEmoji());
        self::assertSame($bot, $stamp->getParticipant());
    }

    public function testParticipantWithoutCountryCannotStamp(): void
    {
        [, , $plane] = $this->seedPlane();
        $stateless = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Sin país');

        try {
            self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $stateless);
            self::fail('Expected StampNotAllowedException.');
        } catch (StampNotAllowedException $e) {
            self::assertSame('A country is required to stamp a plane.', $e->getMessage());
        }

        self::assertCount(0, $this->em->getRepository(PlaneVisit::class)->findBy(['type' => PlaneVisitType::Participation]));
    }

    public function testSecondStampIsRejectedButViewsStillAccumulate(): void
    {
        [, $reader, $plane] = $this->seedPlane();
        $manager = self::getContainer()->get(PlaneVisitManager::class);

        $manager->registerView($plane, $reader);
        $manager->registerParticipation($plane, $reader);
        $manager->registerView($plane, $reader);

        try {
            $manager->registerParticipation($plane, $reader);
            self::fail('Expected AlreadyStampedException.');
        } catch (AlreadyStampedException) {
        }

        $repo = $this->em->getRepository(PlaneVisit::class);
        self::assertCount(2, $repo->findBy(['type' => PlaneVisitType::View]));
        self::assertCount(1, $repo->findBy(['type' => PlaneVisitType::Participation]));
    }

    public function testConcurrentRequestsCannotCreateTwoStamps(): void
    {
        [, $reader, $plane] = $this->seedPlane();
        $manager = self::getContainer()->get(PlaneVisitManager::class);
        $manager->registerParticipation($plane, $reader);

        // A second request that passed the "already stamped?" check before the first one committed:
        // the check is blind (always false), so only the partial unique index can stop it.
        $blind = $this->createStub(PlaneVisitRepository::class);
        $blind->method('hasParticipation')->willReturn(false);
        $racing = new PlaneVisitManager($this->em, $blind);

        $this->expectException(AlreadyStampedException::class);
        $racing->registerParticipation($plane, $reader);
    }

    public function testUniqueIndexOnlyRestrictsParticipations(): void
    {
        [, $reader, $plane] = $this->seedPlane();
        $manager = self::getContainer()->get(PlaneVisitManager::class);

        $manager->registerView($plane, $reader);
        $manager->registerView($plane, $reader);
        $manager->registerView($plane, null);
        $manager->registerView($plane, null);

        self::assertCount(4, $this->em->getRepository(PlaneVisit::class)->findBy(['plane' => $plane]));
    }
}
