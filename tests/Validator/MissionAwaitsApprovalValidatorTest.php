<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Dto\AgentMissionDecision;
use App\Entity\AgentMission;
use App\Enum\AgentMissionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class MissionAwaitsApprovalValidatorTest extends KernelTestCase
{
    private function validate(AgentMission $mission): ?string
    {
        self::bootKernel();
        $decision = new AgentMissionDecision($mission);
        $decision->accepted = true;

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($decision);
        self::assertLessThanOrEqual(1, \count($violations));

        return \count($violations) > 0 ? $violations[0]->getMessage() : null;
    }

    public function testAMissionWaitingForApprovalPasses(): void
    {
        $mission = new AgentMission();
        $mission->requestApproval('El misterio', 'Siete aviones vuelan por el cielo.');

        self::assertNull($this->validate($mission));
    }

    public function testWaitingWithoutAProposalIsNotDecidable(): void
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, AgentMissionStatus::WaitingApproval);

        self::assertSame('This mission is no longer available for approval.', $this->validate($mission));
    }

    /** @return iterable<string, array{AgentMissionStatus, string}> */
    public static function otherStatuses(): iterable
    {
        yield 'completed' => [AgentMissionStatus::Completed, 'This mission has already been approved.'];
        yield 'rejected' => [AgentMissionStatus::Rejected, 'This mission has already been rejected.'];
        yield 'running' => [AgentMissionStatus::Running, 'This mission is not ready for approval yet.'];
        yield 'waiting tool approval' => [AgentMissionStatus::WaitingToolApproval, 'This mission is not ready for approval yet.'];
        yield 'failed' => [AgentMissionStatus::Failed, 'This mission is no longer available for approval.'];
    }

    #[DataProvider('otherStatuses')]
    public function testAnyOtherStatusAddsItsMessage(AgentMissionStatus $status, string $message): void
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);

        self::assertSame($message, $this->validate($mission));
    }
}
