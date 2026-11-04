<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\ApiResource\AgentMission as AgentMissionResource;
use App\Entity\AgentMission;
use App\Enum\AgentMissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Validates the API resource, which carries the constraint, as API Platform does. */
class MissionAvailableToStartValidatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function storeMission(AgentMissionStatus $status): void
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);
        $this->em->persist($mission);
        $this->em->flush();
    }

    public function testPassesWithoutMissions(): void
    {
        self::assertCount(0, $this->validator->validate(new AgentMissionResource()));
    }

    #[DataProvider('activeStatuses')]
    public function testAnActiveMissionAddsAViolation(AgentMissionStatus $status): void
    {
        $this->storeMission($status);

        $violations = $this->validator->validate(new AgentMissionResource());

        self::assertCount(1, $violations);
        self::assertSame('A Sky Pulse mission is already underway. Check back in a few minutes!', $violations[0]->getMessage());
    }

    #[DataProvider('terminalStatuses')]
    public function testATerminalMissionPasses(AgentMissionStatus $status): void
    {
        $this->storeMission($status);

        self::assertCount(0, $this->validator->validate(new AgentMissionResource()));
    }

    /** @return iterable<string, array{AgentMissionStatus}> */
    public static function activeStatuses(): iterable
    {
        yield 'running' => [AgentMissionStatus::Running];
        yield 'waiting tool approval' => [AgentMissionStatus::WaitingToolApproval];
        yield 'waiting approval' => [AgentMissionStatus::WaitingApproval];
    }

    /** @return iterable<string, array{AgentMissionStatus}> */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [AgentMissionStatus::Completed];
        yield 'rejected' => [AgentMissionStatus::Rejected];
        yield 'failed' => [AgentMissionStatus::Failed];
    }
}
