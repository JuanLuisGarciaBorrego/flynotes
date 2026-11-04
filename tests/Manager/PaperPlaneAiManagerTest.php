<?php

declare(strict_types=1);

namespace App\Tests\Manager;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\AiMessageStatus;
use App\Enum\ParticipantType;
use App\Exception\AiMessageNotAllowedException;
use App\Exception\AiMessageStateException;
use App\Manager\PaperPlaneAiManager;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

/** The human-in-the-loop flow as any channel calls it (HTTP and MCP), with the real agent: only the model is scripted. */
class PaperPlaneAiManagerTest extends KernelTestCase
{
    private const string PROPOSAL = 'Hoy el día se puso complicado, y aun así aquí sigues.';
    private const string TONE = 'Tierno';

    private EntityManagerInterface $em;
    private PaperPlaneAiManager $manager;
    private Participant $author;
    private bool $modelIsDown = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        self::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(
            fn (): string => $this->modelIsDown ? throw new \RuntimeException('The model is down.') : json_encode(['text' => self::PROPOSAL, 'tone' => self::TONE], \JSON_THROW_ON_ERROR),
            eventDispatcher: self::getContainer()->get('event_dispatcher'),
        ));
        $this->manager = self::getContainer()->get(PaperPlaneAiManager::class);
        $this->author = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testLaunchPublishesTheOriginalWithAPendingProposal(): void
    {
        $plane = $this->manager->launch($this->author, '  Hoy ha sido un día complicado. ');

        $stored = $this->reload($plane);
        self::assertSame('Hoy ha sido un día complicado.', $stored->getMessage());
        $version = $stored->getCurrentAiVersion();
        self::assertSame(self::PROPOSAL, $version?->getText());
        self::assertSame(self::TONE, $version->getTone());
        self::assertSame(1, $version->getNumber());
        // Pending: nothing published yet.
        self::assertNull($stored->getAiMessage());
        self::assertSame(AiMessageStatus::Pending, $stored->getAiStatus());
        self::assertSame(1, $stored->getAiAttempts());
        self::assertSame(2, $stored->getAiAttemptsRemaining());
    }

    public function testTwoRetriesThenTheLimit(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');

        self::assertSame(2, $this->manager->generateNewPropose($plane, $this->author)->getAiAttempts());
        self::assertSame(3, $this->manager->generateNewPropose($plane, $this->author)->getAiAttempts());

        try {
            $this->manager->generateNewPropose($plane, $this->author);
            self::fail('A fourth generation must be refused.');
        } catch (AiMessageStateException) {
        }
        $stored = $this->reload($plane);
        self::assertSame(3, $stored->getAiAttempts());
        self::assertSame([1, 2, 3], array_map(static fn ($v): int => $v->getNumber(), $stored->getAiVersions()));
        self::assertSame(AiMessageStatus::Pending, $stored->getAiStatus());
        self::assertSame('Hoy ha sido un día complicado.', $stored->getMessage());
    }

    /** Accepting approves the version on screen, the latest. The earlier ones stay as history and can't be approved. */
    public function testOnlyTheLatestVersionCanBeApprovedAndAllAreKept(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $replaced = $plane->getCurrentAiVersion()?->getUuid() ?? self::fail('No proposal.');
        $this->manager->generateNewPropose($plane, $this->author);
        $latest = $plane->getCurrentAiVersion()?->getUuid() ?? self::fail('No proposal.');

        try {
            $this->manager->decide($plane, $this->author, true, $replaced);
            self::fail('A replaced version must not be approved.');
        } catch (AiMessageStateException) {
        }
        self::assertSame(AiMessageStatus::Pending, $plane->getAiStatus());

        $this->manager->decide($plane, $this->author, true, $latest);

        $stored = $this->reload($plane);
        self::assertSame(AiMessageStatus::Accepted, $stored->getAiStatus());
        self::assertTrue($latest->equals($stored->getApprovedAiVersion()?->getUuid()));
        self::assertSame(2, $stored->getApprovedAiVersion()->getNumber());
        self::assertCount(2, $stored->getAiVersions());
    }

    /** While the AI writes, the version on screen is about to be replaced: nothing can be decided or generated. */
    public function testNothingHappensWhileTheAiIsWriting(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $shown = $plane->getCurrentAiVersion()?->getUuid();
        $generating = self::getContainer()->get(LockFactory::class)->createLock(PaperPlaneAiManager::lockName($plane));
        self::assertTrue($generating->acquire());

        foreach ([
            fn () => $this->manager->generateNewPropose($plane, $this->author),
            fn () => $this->manager->decide($plane, $this->author, true, $shown),
            fn () => $this->manager->decide($plane, $this->author, false),
        ] as $action) {
            try {
                $action();
                self::fail('Nothing can happen during a generation.');
            } catch (AiMessageStateException $exception) {
                self::assertStringContainsString('still writing', $exception->getMessage());
            }
        }
        self::assertSame(AiMessageStatus::Pending, $plane->getAiStatus());
        self::assertSame(1, $plane->getAiAttempts());

        $generating->release();
        $this->manager->decide($plane, $this->author, true, $shown);
        self::assertSame(AiMessageStatus::Accepted, $plane->getAiStatus());
    }

    /** A failed generation spends nothing, keeps the version on screen and frees the plane for the next try. */
    public function testAFailedGenerationSpendsNoAttempt(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $shown = $plane->getCurrentAiVersion()?->getUuid();

        $this->modelIsDown = true;
        try {
            $this->manager->generateNewPropose($plane, $this->author);
            self::fail('The model is down.');
        } catch (\RuntimeException) {
        }
        $this->modelIsDown = false;

        self::assertSame(1, $plane->getAiAttempts());
        self::assertTrue($shown?->equals($plane->getCurrentAiVersion()?->getUuid()));
        $this->manager->generateNewPropose($plane, $this->author);
        self::assertSame(2, $plane->getAiAttempts());
    }

    public function testAVersionOfAnotherPlaneIsRefused(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $other = $this->manager->launch($this->author, 'Otro avión de la misma autora.');

        try {
            $this->manager->decide($plane, $this->author, true, $other->getCurrentAiVersion()?->getUuid());
            self::fail('A version of another plane must be refused.');
        } catch (AiMessageStateException) {
        }
        self::assertSame(AiMessageStatus::Pending, $this->reload($plane)->getAiStatus());
    }

    public function testAcceptAndRejectAreFinal(): void
    {
        foreach ([true, false] as $accepted) {
            $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
            $this->manager->decide($plane, $this->author, $accepted, $plane->getCurrentAiVersion()?->getUuid());

            $stored = $this->reload($plane);
            self::assertSame($accepted ? AiMessageStatus::Accepted : AiMessageStatus::Rejected, $stored->getAiStatus());
            self::assertSame($accepted ? self::PROPOSAL : null, $stored->getAiMessage());
            self::assertSame('Hoy ha sido un día complicado.', $stored->getMessage());

            foreach ([fn () => $this->manager->generateNewPropose($stored, $this->author), fn () => $this->manager->decide($stored, $this->author, !$accepted, $stored->getAiVersions()[0]->getUuid())] as $change) {
                try {
                    $change();
                    self::fail('A decided reinterpretation must not change.');
                } catch (AiMessageStateException) {
                }
            }
            self::assertSame($accepted ? AiMessageStatus::Accepted : AiMessageStatus::Rejected, $this->reload($plane)->getAiStatus());
        }
    }

    public function testOnlyTheAuthorCanActOnTheProposal(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $other = self::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Otra', 'FR');

        foreach ([fn () => $this->manager->generateNewPropose($plane, $other), fn () => $this->manager->decide($plane, $other, true, $plane->getCurrentAiVersion()?->getUuid())] as $action) {
            try {
                $action();
                self::fail('Only the author may act on the AI reinterpretation.');
            } catch (AiMessageNotAllowedException) {
            }
        }
        $stored = $this->reload($plane);
        self::assertSame(1, $stored->getAiAttempts());
        self::assertSame(AiMessageStatus::Pending, $stored->getAiStatus());
    }

    /**
     * A retry that read the counter before another one finished, past the lock of the plane or on another server:
     * the unique version number still stops it at the database, so the limit of generations cannot be exceeded.
     */
    public function testAStaleRetryCannotExceedTheLimit(): void
    {
        $plane = $this->manager->launch($this->author, 'Hoy ha sido un día complicado.');
        $connection = $this->em->getConnection();
        // Another request generated meanwhile, behind this EntityManager's back.
        $connection->executeStatement(
            'INSERT INTO ai_message_version (uuid, plane_id, number, text, tone, created_at) SELECT gen_random_uuid(), plane_id, 2, text, tone, created_at FROM ai_message_version WHERE plane_id = ?',
            [$plane->getId()],
        );
        $connection->executeStatement('UPDATE paper_plane SET ai_attempts = 2 WHERE id = ?', [$plane->getId()]);

        try {
            $this->manager->generateNewPropose($plane, $this->author);
            self::fail('A stale retry must not be stored.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(2, $connection->fetchOne('SELECT count(*) FROM ai_message_version WHERE plane_id = ?', [$plane->getId()]));
        self::assertSame(2, $connection->fetchOne('SELECT ai_attempts FROM paper_plane WHERE id = ?', [$plane->getId()]));
    }

    /** A plane without proposal is what a failed launch leaves: asking again generates its first one. */
    public function testARetryOnAPlaneWithoutProposalGeneratesTheFirstOne(): void
    {
        $plain = self::getContainer()->get(PaperPlaneManager::class)->create($this->author, 'Un avión sin IA');

        $this->manager->decide($this->manager->generateNewPropose($plain, $this->author), $this->author, false);

        $stored = $this->reload($plain);
        self::assertSame(1, $stored->getAiAttempts());
        self::assertSame(AiMessageStatus::Rejected, $stored->getAiStatus());

        $this->expectException(AiMessageStateException::class);
        $this->manager->generateNewPropose($stored, $this->author);
    }

    public function testNoDecisionIsPossibleWithoutAProposal(): void
    {
        $plain = self::getContainer()->get(PaperPlaneManager::class)->create($this->author, 'Un avión sin IA');

        $this->expectException(AiMessageStateException::class);
        $this->manager->decide($plain, $this->author, false);
    }

    /** The plane as stored in the database (re-read, still managed). */
    private function reload(PaperPlane $plane): PaperPlane
    {
        $this->em->refresh($plane);

        return $plane;
    }
}
