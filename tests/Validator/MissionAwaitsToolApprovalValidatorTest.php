<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Dto\AgentMissionToolDecision;
use App\Entity\AgentMission;
use App\Enum\AgentMissionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class MissionAwaitsToolApprovalValidatorTest extends KernelTestCase
{
    private function validate(AgentMission $mission): ?string
    {
        self::bootKernel();
        $decision = new AgentMissionToolDecision($mission);
        $decision->accepted = true;

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($decision);
        self::assertLessThanOrEqual(1, \count($violations));

        return \count($violations) > 0 ? $violations[0]->getMessage() : null;
    }

    private function withStatus(AgentMissionStatus $status): AgentMission
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);

        return $mission;
    }

    public function testAPendingToolPasses(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_emojis', [], []);

        self::assertNull($this->validate($mission));
    }

    public function testWaitingWithoutAToolIsNotDecidable(): void
    {
        self::assertSame('This mission is no longer available for approval.', $this->validate($this->withStatus(AgentMissionStatus::WaitingToolApproval)));
    }

    public function testAnAlreadyApprovedToolIsNotDecidable(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_emojis', [], []);
        $mission->beginApprovedTool();

        self::assertSame('This tool has already been decided.', $this->validate($mission));
    }

    /** @return iterable<string, array{AgentMissionStatus, string}> */
    public static function otherStatuses(): iterable
    {
        yield 'running' => [AgentMissionStatus::Running, 'This tool has already been decided.'];
        yield 'waiting approval' => [AgentMissionStatus::WaitingApproval, 'This tool has already been decided.'];
        yield 'completed' => [AgentMissionStatus::Completed, 'This mission has already been approved.'];
        yield 'rejected' => [AgentMissionStatus::Rejected, 'This mission has already been rejected.'];
        yield 'failed' => [AgentMissionStatus::Failed, 'This mission is no longer available for approval.'];
    }

    #[DataProvider('otherStatuses')]
    public function testAnyOtherStatusAddsItsMessage(AgentMissionStatus $status, string $message): void
    {
        self::assertSame($message, $this->validate($this->withStatus($status)));
    }
}
