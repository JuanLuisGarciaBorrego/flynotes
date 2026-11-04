<?php

declare(strict_types=1);

namespace App\Tests\Manager;

use App\Entity\PaperPlane;
use App\Entity\Participant;
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
use Symfony\Component\Uid\Uuid;

/** getRandom(), show(), getOwn() and getOwnStats() as a channel without HTTP (the MCP tools) calls them: no Operation, no request. */
class PaperPlaneManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaperPlaneManager $manager;
    private ParticipantManager $participants;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->manager = self::getContainer()->get(PaperPlaneManager::class);
        $this->participants = self::getContainer()->get(ParticipantManager::class);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /** Planes created without the AI flow (MCP bots, older planes) keep working with empty AI fields. */
    public function testCreateLeavesTheAiFieldsEmpty(): void
    {
        $plane = $this->manager->create($this->participants->create(ParticipantType::Bot, 'Bot'), '  Un avión sin reinterpretación ');
        $this->em->clear();

        $stored = $this->em->find(PaperPlane::class, $plane->getId());
        self::assertSame('Un avión sin reinterpretación', $stored?->getMessage());
        self::assertNull($stored->getAiMessage());
        self::assertNull($stored->getAiStatus());
        self::assertSame(0, $stored->getAiAttempts());
    }

    public function testGetRandomReturnsAtMostTheLimitAndRecordsNoVisit(): void
    {
        $author = $this->participants->create(ParticipantType::Human, 'Autor');
        for ($i = 0; $i < PaperPlaneManager::RANDOM_LIMIT + 5; ++$i) {
            $this->manager->create($author, 'Mensaje '.$i);
        }

        $planes = $this->manager->getRandom();

        self::assertCount(PaperPlaneManager::RANDOM_LIMIT, $planes);
        self::assertCount(PaperPlaneManager::RANDOM_LIMIT, array_unique(array_map(static fn ($plane): string => $plane->getUuid()->toRfc4122(), $planes)));
        self::assertSame(0, $this->countVisits());
    }

    public function testShowUnknownUuidReturnsNullAndRecordsNothing(): void
    {
        $reader = $this->participants->create(ParticipantType::Bot, 'Bot');

        self::assertNull($this->manager->show(Uuid::v7(), $reader));
        self::assertSame(0, $this->countVisits());
    }

    public function testShowRecordsTheViewAndCountsIt(): void
    {
        $author = $this->participants->create(ParticipantType::Human, 'Autor', 'ES');
        $bot = $this->participants->create(ParticipantType::Bot, 'Bot', 'FR');
        $plane = $this->manager->create($author, 'Hola');

        $detail = $this->manager->show($plane->getUuid(), $bot);

        self::assertNotNull($detail);
        self::assertSame($plane->getId(), $detail['plane']->getId());
        self::assertSame(1, $detail['viewCount'], 'The count includes the read just recorded.');
        self::assertSame([], $detail['stamps']);
        self::assertFalse($detail['isAuthor']);
        self::assertFalse($detail['hasStamped']);
        // Bots with a country stamp like humans.
        self::assertTrue($detail['canStamp']);
        self::assertSame(2, $this->manager->show($plane->getUuid(), null)['viewCount'], 'Anonymous reads count too.');
    }

    public function testShowWithoutRecordingKeepsTheCount(): void
    {
        $author = $this->participants->create(ParticipantType::Human, 'Autor');
        $plane = $this->manager->create($author, 'Hola');

        self::assertSame(0, $this->manager->show($plane->getUuid(), null, recordView: false)['viewCount']);
        self::assertSame(0, $this->countVisits());
    }

    public function testAuthorReadIsNotAVisit(): void
    {
        $author = $this->participants->create(ParticipantType::Human, 'Autor', 'ES');
        $plane = $this->manager->create($author, 'Hola');

        $detail = $this->manager->show($plane->getUuid(), $author);

        self::assertSame(0, $detail['viewCount']);
        self::assertTrue($detail['isAuthor']);
        self::assertFalse($detail['hasStamped']);
        self::assertFalse($detail['canStamp']);
    }

    public function testViewerFlagsFollowTheStamps(): void
    {
        $author = $this->participants->create(ParticipantType::Human, 'Autor', 'ES');
        $human = $this->participants->create(ParticipantType::Human, 'Lectora', 'FR');
        $stateless = $this->participants->create(ParticipantType::Human, 'Sin país');
        $plane = $this->manager->create($author, 'Hola');

        self::assertTrue($this->manager->show($plane->getUuid(), $human)['canStamp']);
        self::assertFalse($this->manager->show($plane->getUuid(), $stateless)['canStamp'], 'A human without a country cannot stamp.');

        $stamp = self::getContainer()->get(PlaneVisitManager::class)->registerParticipation($plane, $human);
        $detail = $this->manager->show($plane->getUuid(), $human);

        self::assertTrue($detail['hasStamped']);
        self::assertFalse($detail['canStamp']);
        self::assertSame([$stamp->getId()], array_map(static fn (PlaneVisit $visit): ?int => $visit->getId(), $detail['stamps']));
        self::assertSame(PlaneVisitType::Participation, $detail['stamps'][0]->getType());
    }

    public function testAnonymousViewerCannotStamp(): void
    {
        $plane = $this->manager->create($this->participants->create(ParticipantType::Human, 'Autor', 'ES'), 'Hola');

        $detail = $this->manager->show($plane->getUuid(), null);

        self::assertFalse($detail['isAuthor']);
        self::assertFalse($detail['hasStamped']);
        self::assertFalse($detail['canStamp']);
    }

    /**
     * `canStamp` is only a hint: registerParticipation() enforces the same rules. For every kind of viewer,
     * the flag predicts whether stamping succeeds.
     *
     * @return iterable<string, array{ParticipantType, ?string, bool, bool}>
     */
    public static function stampers(): iterable
    {
        // type, country, is the author, already stamped
        yield 'human' => [ParticipantType::Human, 'FR', false, false];
        yield 'bot' => [ParticipantType::Bot, 'PT', false, false];
        yield 'human without country' => [ParticipantType::Human, null, false, false];
        yield 'bot without country' => [ParticipantType::Bot, null, false, false];
        yield 'human author' => [ParticipantType::Human, 'ES', true, false];
        yield 'bot author' => [ParticipantType::Bot, 'ES', true, false];
        yield 'human already stamped' => [ParticipantType::Human, 'FR', false, true];
        yield 'bot already stamped' => [ParticipantType::Bot, 'PT', false, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stampers')]
    public function testCanStampMatchesWhatRegisterParticipationAllows(ParticipantType $type, ?string $country, bool $isAuthor, bool $alreadyStamped): void
    {
        $viewer = $this->participants->create($type, 'Visitante', $country);
        $plane = $this->plane($isAuthor ? $viewer : $this->participants->create(ParticipantType::Human, 'Autor', 'ES'));
        $stamps = self::getContainer()->get(PlaneVisitManager::class);
        if ($alreadyStamped) {
            $stamps->registerParticipation($plane, $viewer);
        }

        $canStamp = $this->manager->show($plane->getUuid(), $viewer)['canStamp'];

        try {
            $stamps->registerParticipation($plane, $viewer);
            $stamped = true;
        } catch (StampNotAllowedException|AlreadyStampedException) {
            $stamped = false;
        }

        self::assertSame(!$isAuthor && null !== $country && !$alreadyStamped, $canStamp);
        self::assertSame($canStamp, $stamped);
    }

    public function testGetOwnReturnsOnePageOfTheAuthorsPlanesNewestFirstWithStampsAndRecordsNoVisit(): void
    {
        $author = $this->participants->create(ParticipantType::Bot, 'Autor', 'ES');
        $reader = $this->participants->create(ParticipantType::Human, 'Lectora', 'FR');
        $this->manager->create($reader, 'Un avión ajeno');
        $planes = [];
        for ($i = 1; $i <= PaperPlaneManager::OWN_PER_PAGE + 2; ++$i) {
            $planes[$i] = $this->manager->create($author, 'Mensaje '.$i)->setCreatedAt(new \DateTimeImmutable(\sprintf('2026-01-%02d 10:00', $i)));
        }
        $this->em->flush();
        $visits = self::getContainer()->get(PlaneVisitManager::class);
        $visits->registerView($planes[22], $reader);
        $stamp = $visits->registerParticipation($planes[22], $reader);
        $before = $this->countVisits();

        $first = $this->manager->getOwn($author, 1);
        $second = $this->manager->getOwn($author, 2);

        self::assertSame(PaperPlaneManager::OWN_PER_PAGE + 2, $first['total']);
        self::assertCount(PaperPlaneManager::OWN_PER_PAGE, $first['items']);
        self::assertSame($planes[22]->getId(), $first['items'][0]['plane']->getId());
        self::assertSame(1, $first['items'][0]['viewCount']);
        self::assertSame([$stamp->getId()], array_map(static fn (PlaneVisit $visit): ?int => $visit->getId(), $first['items'][0]['stamps']));
        self::assertSame([], $first['items'][1]['stamps']);
        self::assertSame([$planes[2]->getId(), $planes[1]->getId()], array_map(static fn (array $item): ?int => $item['plane']->getId(), $second['items']));
        self::assertSame([], $this->manager->getOwn($author, 3)['items']);
        self::assertSame($before, $this->countVisits());
    }

    public function testGetOwnStatsAreTheAuthorsTotals(): void
    {
        $author = $this->participants->create(ParticipantType::Bot, 'Autor', 'ES');
        $french = $this->participants->create(ParticipantType::Human, 'Lectora', 'FR');
        self::assertSame(['planes' => 0, 'views' => 0, 'countries' => 0], $this->manager->getOwnStats($author));

        $plane = $this->manager->create($author, 'Hola');
        $visits = self::getContainer()->get(PlaneVisitManager::class);
        $visits->registerView($plane, $french);
        $visits->registerView($plane, null);
        $visits->registerParticipation($plane, $french);
        $before = $this->countVisits();

        self::assertSame(['planes' => 1, 'views' => 2, 'countries' => 1], $this->manager->getOwnStats($author));
        self::assertSame(['planes' => 0, 'views' => 0, 'countries' => 0], $this->manager->getOwnStats($french));
        self::assertSame($before, $this->countVisits());
    }

    private function plane(Participant $author): PaperPlane
    {
        return $this->manager->create($author, 'Hola');
    }

    private function countVisits(): int
    {
        return self::getContainer()->get(PlaneVisitRepository::class)->count([]);
    }
}
