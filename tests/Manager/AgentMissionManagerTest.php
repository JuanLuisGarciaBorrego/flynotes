<?php

declare(strict_types=1);

namespace App\Tests\Manager;

use App\Entity\AgentMission;
use App\Entity\AgentMissionStep;
use App\Entity\PaperPlane;
use App\Enum\AgentMissionStatus;
use App\Enum\AgentMissionStepType;
use App\Enum\ParticipantType;
use App\EventListener\ToolApprovalListener;
use App\Manager\AgentMissionManager;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Repository\AgentMissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** launch() and the model side of decideTool(), with the real `sky_pulse` agent, tools and interception. */
class AgentMissionManagerTest extends KernelTestCase
{
    private const string MESSAGE = 'Siete aviones vuelan por el cielo.';

    private EntityManagerInterface $em;
    private int $toolRuns = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        self::getContainer()->get('event_dispatcher')->addListener(ToolCallSucceeded::class, function (): void {
            ++$this->toolRuns;
        });
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function manager(\Closure $model, LoggerInterface $logger = new NullLogger(), ?ToolboxInterface $toolbox = null): AgentMissionManager
    {
        $container = self::getContainer();
        $container->set('ai.platform.generic.default', MockPlatformFactory::createPlatform($model));

        return new AgentMissionManager(
            $container->get('ai.agent.sky_pulse'),
            $toolbox ?? $container->get('ai.toolbox.sky_pulse'),
            $container->get('serializer'),
            $this->em,
            $logger,
            $container->get(PaperPlaneManager::class),
            $container->get(ParticipantManager::class),
            $container->get(ToolApprovalListener::class),
            true,
        );
    }

    private function reload(AgentMission $mission): AgentMission
    {
        $uuid = $mission->getUuid();
        $this->em->clear();

        return $this->em->getRepository(AgentMission::class)->findOneBy(['uuid' => $uuid]);
    }

    /** @param list<ToolCall> $calls */
    private function tools(array $calls, int $input = 0, int $output = 0): ResultInterface
    {
        $result = new ToolCallResult($calls);
        if ($input > 0) {
            $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: $input, completionTokens: $output));
        }

        return $result;
    }

    private function research(string $name = 'get_sky_emojis'): ToolCall
    {
        return new ToolCall('call_'.$name, $name);
    }

    private function publish(string $title = 'El misterio', string $message = self::MESSAGE): ToolCall
    {
        return new ToolCall('call_publish', 'publish_sky_report', ['title' => $title, 'message' => $message]);
    }

    /** @return list<array{AgentMissionStepType, ?string}> */
    private function steps(AgentMission $mission): array
    {
        return array_map(
            static fn (AgentMissionStep $step): array => [$step->getType(), $step->getToolName()],
            $mission->getSteps()->getValues(),
        );
    }

    public function testNoTokensAreInventedWhenTheProviderReportsNone(): void
    {
        $mission = $this->manager(fn (): ResultInterface => $this->tools([$this->research()]))->launch();

        self::assertSame(0, $this->reload($mission)->getTotalTokens());
    }

    public function testTheMissionIsAlreadyActiveWhileTheAgentRuns(): void
    {
        $activeDuringTheRun = null;
        $this->manager(function () use (&$activeDuringTheRun): ResultInterface {
            $activeDuringTheRun = self::getContainer()->get(AgentMissionRepository::class)->hasActiveMission();

            return $this->tools([$this->research()]);
        })->launch();

        self::assertTrue($activeDuringTheRun);
    }

    public function testOnlyTheFirstOfSeveralRequestedToolsIsPendingAndTheOthersAreRecordedAndAnswered(): void
    {
        $inputs = [];
        $manager = $this->manager(function ($model, $input) use (&$inputs): ResultInterface {
            $inputs[] = $input;

            return 1 === \count($inputs)
                ? $this->tools([$this->research('get_sky_emojis'), $this->research('get_sky_activity')])
                : $this->tools([$this->publish()]);
        });

        $mission = $this->reload($manager->launch());

        self::assertSame(AgentMissionStatus::WaitingToolApproval, $mission->getStatus());
        self::assertSame('get_sky_emojis', $mission->getPendingToolName());
        self::assertSame([
            [AgentMissionStepType::Started, null],
            [AgentMissionStepType::ToolCalled, 'get_sky_emojis'],
            [AgentMissionStepType::ToolCalled, 'get_sky_activity'],
            [AgentMissionStepType::Rejected, 'get_sky_activity'],
            [AgentMissionStepType::ApprovalRequested, 'get_sky_emojis'],
        ], $this->steps($mission));
        self::assertSame(0, $this->toolRuns);

        $mission = $manager->decideTool($mission, true);

        self::assertSame(AgentMissionStatus::WaitingApproval, $mission->getStatus());
        self::assertSame(1, $this->toolRuns, 'Only the authorized one runs.');

        // Every call of the conversation has its answer, as the provider requires.
        $toolMessages = array_values(array_filter($inputs[1]['messages'], static fn (array $message): bool => 'tool' === $message['role']));
        self::assertSame(['call_get_sky_emojis', 'call_get_sky_activity'], array_column($toolMessages, 'tool_call_id'));
        self::assertSame('Not executed: only one tool can run per mission.', $toolMessages[1]['content']);
    }

    /** @return iterable<string, array{string}> */
    public static function toolsThatCannotRunInTheFirstPhase(): iterable
    {
        yield 'publication, not offered yet' => ['publish_sky_report'];
        yield 'unknown tool' => ['drop_everything'];
    }

    #[DataProvider('toolsThatCannotRunInTheFirstPhase')]
    public function testAToolThatIsNotOfferedNeverRuns(string $name): void
    {
        $mission = $this->manager(fn (): ResultInterface => $this->tools([new ToolCall('call_x', $name, ['title' => 'Título', 'message' => self::MESSAGE])]))->launch();

        $stored = $this->reload($mission);
        self::assertSame(AgentMissionStatus::Failed, $stored->getStatus());
        self::assertNull($stored->getPendingToolName());
        self::assertSame(0, $this->toolRuns);
    }

    public function testTheContinuationCannotRunAnotherResearchTool(): void
    {
        $rounds = 0;
        $manager = $this->manager(function () use (&$rounds): ResultInterface {
            return 1 === ++$rounds ? $this->tools([$this->research()]) : $this->tools([$this->research('get_sky_activity')]);
        });

        $mission = $manager->decideTool($manager->launch(), true);

        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertSame(1, $this->toolRuns, 'Only the authorized one.');
    }

    public function testAnApprovalCannotBeReusedByAnotherRequestOfTheSameTool(): void
    {
        $rounds = 0;
        $manager = $this->manager(function () use (&$rounds): ResultInterface {
            return 1 === ++$rounds ? $this->tools([$this->research()]) : $this->tools([$this->publish()]);
        });
        $repository = self::getContainer()->get(AgentMissionRepository::class);

        $mission = $manager->decideTool($manager->launch(), true);

        self::assertSame(AgentMissionStatus::WaitingApproval, $mission->getStatus());
        self::assertFalse($repository->isToolCallApproved('call_get_sky_emojis', 'get_sky_emojis'));
    }

    public function testAnApprovalOnlyCoversThatCall(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_emojis', [], []);
        $mission->beginApprovedTool();
        $this->em->persist($mission);
        $this->em->flush();
        $repository = self::getContainer()->get(AgentMissionRepository::class);

        self::assertTrue($repository->isToolCallApproved('call_1', 'get_sky_emojis'));
        self::assertFalse($repository->isToolCallApproved('call_2', 'get_sky_emojis'));
        self::assertFalse($repository->isToolCallApproved('call_1', 'get_sky_activity'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPublications(): iterable
    {
        yield 'blank title' => ['   ', self::MESSAGE];
        yield 'title too long' => [str_repeat('x', AgentMission::TITLE_MAX_LENGTH + 1), self::MESSAGE];
        yield 'message too short' => ['El misterio', 'Corto'];
        yield 'message too long' => ['El misterio', str_repeat('x', PaperPlane::MESSAGE_MAX_LENGTH + 1)];
    }

    #[DataProvider('invalidPublications')]
    public function testAnInvalidPublicationGoesBackToTheModelToCorrectIt(string $title, string $message): void
    {
        $inputs = [];
        $manager = $this->manager(function ($model, $input) use (&$inputs, $title, $message): ResultInterface {
            $inputs[] = $input;

            return match (\count($inputs)) {
                1 => $this->tools([$this->research()]),
                2 => $this->tools([$this->publish($title, $message)]),
                default => $this->tools([$this->publish()]),
            };
        });

        $mission = $manager->decideTool($manager->launch(), true);

        self::assertCount(3, $inputs);
        self::assertStringContainsString('Invalid publication request', (string) json_encode($inputs[2]));
        self::assertSame(AgentMissionStatus::WaitingApproval, $mission->getStatus());
        self::assertSame(self::MESSAGE, $mission->getProposedMessage());
    }

    /** One correction: a second invalid request fails the mission before another paid call, with nothing to approve. */
    public function testAPublicationStillInvalidAfterItsCorrectionFailsWithoutCallingTheModelAgain(): void
    {
        $inputs = [];
        $manager = $this->manager(function ($model, $input) use (&$inputs): ResultInterface {
            $inputs[] = $input;

            return 1 === \count($inputs) ? $this->tools([$this->research()]) : $this->tools([$this->publish('   ')]);
        });

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertCount(3, $inputs, 'The research, the invalid request and its only correction.');
        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertNull($mission->getProposedMessage());
        self::assertSame([], $this->stepsOfType($mission, AgentMissionStepType::ProposalCreated));
    }

    public function testArgumentsThatAreNotJsonAreCorrectedOnce(): void
    {
        $inputs = [];
        $manager = $this->manager(function ($model, $input) use (&$inputs): ResultInterface {
            $inputs[] = $input;

            return match (\count($inputs)) {
                1 => throw new MalformedToolCallException('Not JSON.'),
                2 => $this->tools([$this->research()]),
                3 => throw new MalformedToolCallException('Not JSON.'),
                default => $this->tools([$this->publish()]),
            };
        });

        $mission = $this->reload($manager->launch());
        self::assertSame(AgentMissionStatus::WaitingToolApproval, $mission->getStatus());
        self::assertCount(2, $inputs);
        self::assertStringContainsString('not valid JSON', (string) json_encode($inputs[1]));

        // Another correction in this same run of the kernel would be a second one: see the reset test for new requests.
        self::getContainer()->get(ToolApprovalListener::class)->reset();
        $mission = $manager->decideTool($mission, true);

        self::assertSame(AgentMissionStatus::WaitingApproval, $mission->getStatus());
        self::assertCount(4, $inputs);
    }

    /** The budget is shared: an invalid request after a malformed one, or the other way round, is not corrected again. */
    #[DataProvider('invalidThenMalformed')]
    public function testMalformedAndInvalidArgumentsShareTheOnlyCorrection(bool $malformedFirst): void
    {
        $calls = 0;
        $manager = $this->manager(function () use (&$calls, $malformedFirst): ResultInterface {
            ++$calls;

            if (1 === $calls) {
                return $this->tools([$this->research()]);
            }

            return (2 === $calls) === $malformedFirst
                ? throw new MalformedToolCallException('Not JSON.') : $this->tools([$this->publish('   ')]);
        });

        $mission = $manager->decideTool($manager->launch(), true);

        self::assertSame(3, $calls);
        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertNull($mission->getProposedMessage());
    }

    /** @return iterable<string, array{bool}> */
    public static function invalidThenMalformed(): iterable
    {
        yield 'malformed, then invalid' => [true];
        yield 'invalid, then malformed' => [false];
    }

    public function testAProviderFailureDuringTheCorrectionIsNotRetried(): void
    {
        $calls = 0;
        $manager = $this->manager(function () use (&$calls): ResultInterface {
            return match (++$calls) {
                1 => $this->tools([$this->research()]),
                2 => $this->tools([$this->publish('   ')]),
                default => throw new \RuntimeException('Provider down'),
            };
        });

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(3, $calls);
        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertNull($mission->getProposedMessage());
    }

    public function testAnAgentExceptionFailsTheMissionAndIsLogged(): void
    {
        $exception = new \RuntimeException('Provider down');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $exception === $context['exception']),
        );

        $mission = $this->manager(static function () use ($exception): never {
            throw $exception;
        }, $logger)->launch();

        $stored = $this->reload($mission);
        self::assertSame(AgentMissionStatus::Failed, $stored->getStatus());
        self::assertSame([
            [AgentMissionStepType::Started, null],
            [AgentMissionStepType::Failed, null],
        ], $this->steps($stored));
    }

    public function testAContinuationFailureLeavesTheMissionFailedWithTheToolAlreadyRun(): void
    {
        $rounds = 0;
        $manager = $this->manager(function () use (&$rounds): ResultInterface {
            if (1 === ++$rounds) {
                return $this->tools([$this->research()]);
            }

            throw new \RuntimeException('Provider down');
        });

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertSame(1, $this->toolRuns);
        self::assertSame([AgentMissionStepType::ToolSucceeded, 'get_sky_emojis'], $this->steps($mission)[4]);
        self::assertSame([AgentMissionStepType::Failed, null], $this->steps($mission)[5]);
    }

    /** A model answering the research with its publication, after asking for the given tool. */
    private function scriptedModel(string $tool = 'get_sky_emojis'): \Closure
    {
        $rounds = 0;

        return function () use (&$rounds, $tool): ResultInterface {
            return 1 === ++$rounds ? $this->tools([$this->research($tool)]) : $this->tools([$this->publish()]);
        };
    }

    /** Steps of that type, as stored. @return list<AgentMissionStep> */
    private function stepsOfType(AgentMission $mission, AgentMissionStepType $type): array
    {
        return array_values($mission->getSteps()->filter(static fn (AgentMissionStep $step): bool => $type === $step->getType())->toArray());
    }

    /** @param list<array{string, int}> $countries country code and number of participants */
    private function participantsIn(array $countries): void
    {
        $participants = self::getContainer()->get(ParticipantManager::class);
        foreach ($countries as [$code, $total]) {
            for ($i = 0; $i < $total; ++$i) {
                $participants->create(ParticipantType::Human, 'Humana', $code);
            }
        }
    }

    /** A real toolbox, with its whole event cycle, around a tool that returns the given value. */
    private function toolboxReturning(mixed $value): ToolboxInterface
    {
        $tool = new #[AsTool('get_sky_emojis', 'Returns anything.')] class($value) {
            public function __construct(private mixed $value)
            {
            }

            public function __invoke(): mixed
            {
                return $this->value;
            }
        };

        return new Toolbox([$tool], eventDispatcher: self::getContainer()->get('event_dispatcher'));
    }

    public function testTheRealToolResultIsStoredOnceAsTheToolboxReturnedIt(): void
    {
        $this->participantsIn([['ES', 4], ['FR', 3]]);
        $fromToolbox = [];
        self::getContainer()->get('event_dispatcher')->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use (&$fromToolbox): void {
            $fromToolbox[] = $event->getResult()->getResult();
        });

        $manager = $this->manager($this->scriptedModel('get_sky_countries'));
        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        $succeeded = $this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded);
        self::assertCount(1, $succeeded);
        self::assertCount(1, $fromToolbox, 'The tool is not run again to get its data.');
        self::assertSame('get_sky_countries', $succeeded[0]->getToolName());
        self::assertSame(
            json_encode(['result' => [['countryCode' => 'ES', 'total' => 4], ['countryCode' => 'FR', 'total' => 3]]]),
            json_encode($succeeded[0]->getPayload()),
        );
        self::assertSame(json_encode($fromToolbox[0]), json_encode($succeeded[0]->getPayload()['result']));
    }

    public function testTheEmojisToolStoresWhatItReallyCounted(): void
    {
        $participants = self::getContainer()->get(ParticipantManager::class);
        foreach (['🦊', '🦊', '🦊'] as $emoji) {
            $participants->create(ParticipantType::Human, 'Humana', 'ES')->setEmoji($emoji);
        }
        $this->em->flush();
        $manager = $this->manager($this->scriptedModel());

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(
            json_encode([['emoji' => '🦊', 'total' => 3]]),
            json_encode($this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded)[0]->getPayload()['result']),
        );
    }

    public function testTheActivityToolKeepsItsMapStructure(): void
    {
        $manager = $this->manager($this->scriptedModel('get_sky_activity'));

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(
            '{"planes":0,"views":0,"stamps":0}',
            json_encode($this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded)[0]->getPayload()['result']),
        );
    }

    public function testARejectedToolStoresNoResult(): void
    {
        $manager = $this->manager($this->scriptedModel());

        $mission = $this->reload($manager->decideTool($manager->launch(), false));

        self::assertSame(0, $this->toolRuns);
        self::assertSame([], $this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded));
        foreach ($mission->getSteps() as $step) {
            self::assertNull($step->getPayload());
        }
    }

    public function testAFailingToolStoresNoResultNorSuccess(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(ToolCallArgumentsResolved::class, static function (): never {
            throw new \RuntimeException('Tool down');
        });
        $manager = $this->manager($this->scriptedModel());

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertSame([], $this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded));
        self::assertNull($this->stepsOfType($mission, AgentMissionStepType::ToolFailed)[0]->getPayload());
    }

    private function assertFailsClosed(\Closure $model, int &$modelCalls, ToolboxInterface $toolbox, int $toolRuns = 0): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $manager = $this->manager($model, $logger, $toolbox);
        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertSame([], $this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded));
        self::assertCount(1, $this->stepsOfType($mission, AgentMissionStepType::ToolFailed));
        self::assertCount(1, $this->stepsOfType($mission, AgentMissionStepType::Failed));
        self::assertSame([], $this->stepsOfType($mission, AgentMissionStepType::ProposalCreated));
        self::assertNull($mission->getTitle());
        self::assertNull($mission->getProposedMessage());
        self::assertSame(1, $modelCalls, 'The model is not called without a real result.');
        self::assertSame($toolRuns, $this->toolRuns);
        foreach ($mission->getSteps() as $step) {
            self::assertNull($step->getPayload(), 'Nothing of it is stored.');
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function resultsThatCannotBeJson(): iterable
    {
        yield 'infinite number' => [\INF];
        yield 'not a number, nested' => [['ratio' => \NAN]];
        yield 'text that is not UTF-8' => ["caf\xE9"];
        yield 'nested text that is not UTF-8' => [['name' => "caf\xE9"]];
    }

    /** A real invocation, so it counts as run once, but without a result to go on with. */
    #[DataProvider('resultsThatCannotBeJson')]
    public function testAResultThatCannotBeJsonIsAFailureOfTheToolAndNeverReachesTheModel(mixed $returned): void
    {
        $rounds = 0;
        $this->assertFailsClosed(function () use (&$rounds): ResultInterface {
            // A publication if it is ever asked again: the assertion fails, and the agent does not loop on research.
            return 1 === ++$rounds ? $this->tools([$this->research()]) : $this->tools([$this->publish()]);
        }, $rounds, $this->toolboxReturning($returned), toolRuns: 1);
    }

    /** The real listener, with the real marker: its authorization is no longer there when the tool is asked to run. */
    public function testAnInterceptedToolIsAFailureAndItsMarkerIsNeverAResult(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(ToolCallRequested::class, function (): void {
            $this->em->getConnection()->executeStatement('UPDATE agent_mission SET pending_tool_approved = false');
        }, 100);
        $rounds = 0;

        $this->assertFailsClosed(function () use (&$rounds): ResultInterface {
            // A publication if it is ever asked again: the assertion fails, and the agent does not loop on research.
            return 1 === ++$rounds ? $this->tools([$this->research()]) : $this->tools([$this->publish()]);
        }, $rounds, self::getContainer()->get('ai.toolbox.sky_pulse'));
    }

    public function testTheResultIsAlreadyInTheDatabaseWhenTheModelFails(): void
    {
        $storedDuringTheModel = null;
        $rounds = 0;
        $connection = $this->em->getConnection();
        $manager = $this->manager(function () use (&$rounds, &$storedDuringTheModel, $connection): ResultInterface {
            if (1 === ++$rounds) {
                return $this->tools([$this->research('get_sky_activity')]);
            }

            $storedDuringTheModel = $connection->fetchOne("SELECT payload FROM agent_mission_step WHERE type = 'tool_succeeded'");

            throw new \RuntimeException('Provider down');
        });

        $mission = $this->reload($manager->decideTool($manager->launch(), true));

        $expected = '{"result":{"planes":0,"views":0,"stamps":0}}';
        self::assertSame($expected, $storedDuringTheModel, 'Stored before the model is called.');
        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertSame($expected, json_encode($this->stepsOfType($mission, AgentMissionStepType::ToolSucceeded)[0]->getPayload()));
    }

    /** Also when the text looks like a proposal: only a real tool call counts. */
    public function testFinishingWithoutRequestingAnythingIsAFailure(): void
    {
        $launched = $this->manager(static fn (): ResultInterface => new TextResult('get_sky_emojis, please.'))->launch();
        self::assertSame(AgentMissionStatus::Failed, $this->reload($launched)->getStatus());
    }

    public function testFinishingWithoutRequestingThePublicationIsAFailure(): void
    {
        $rounds = 0;
        $manager = $this->manager(function () use (&$rounds): ResultInterface {
            return 1 === ++$rounds ? $this->tools([$this->research()]) : new TextResult('publish_sky_report: Siete aviones vuelan.');
        });

        $mission = $manager->decideTool($manager->launch(), true);

        self::assertSame(AgentMissionStatus::Failed, $mission->getStatus());
        self::assertNull($mission->getProposedMessage());
    }
}
