<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\AgentMissionStatus;
use App\Enum\AgentMissionStepType;
use App\Enum\ParticipantType;
use App\EventListener\ToolApprovalListener;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * The whole human-in-the-loop over HTTP, with the real `sky_pulse` agent, toolbox, tools and listeners: only the
 * model's answers are scripted, and they are seen exactly as the provider would receive them.
 */
class AgentMissionToolDecisionApiTest extends WebTestCase
{
    private const string MESSAGE = 'Siete aviones vuelan por el cielo.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<array{input: mixed, tools: list<string>}> */
    private array $modelCalls = [];
    /** @var list<string> */
    private array $toolRuns = [];
    private bool $continuationFails = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input, array $options): ResultInterface {
            $this->modelCalls[] = ['input' => $input, 'tools' => array_column(array_column($options['tools'], 'function'), 'name')];

            if ($this->continuationFails && \count($this->modelCalls) > 1) {
                throw new \RuntimeException('Provider down');
            }

            return $this->withTokens(new ToolCallResult(1 === \count($this->modelCalls)
                ? [new ToolCall('call_research', 'get_sky_countries')]
                : [new ToolCall('call_publish', 'publish_sky_report', ['title' => 'El misterio', 'message' => self::MESSAGE])]), 100, 10);
        }));
        static::getContainer()->get('event_dispatcher')->addListener(ToolCallSucceeded::class, function (ToolCallSucceeded $event): void {
            $this->toolRuns[] = $event->getDefinition()->getName();
        });
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function withTokens(ResultInterface $result, int $input, int $output): ResultInterface
    {
        $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: $input, completionTokens: $output));

        return $result;
    }

    private function human(): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', 'ES');
    }

    /** @return array<string, mixed> */
    private function post(string $uri, string $body): array
    {
        $this->client->request('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function startMission(): string
    {
        $this->client->loginUser($this->human(), 'main');
        $data = $this->post('/api/agent_missions', '{}');
        self::assertSame('waiting_tool_approval', $data['status']);

        return $data['uuid'];
    }

    /** @return array<string, mixed> */
    private function decideTool(string $uuid, string $body = '{"accepted": true}'): array
    {
        return $this->post(sprintf('/api/agent_missions/%s/tool-decision', $uuid), $body);
    }

    /** @return list<array{string, ?string}> */
    private function timeline(array $data): array
    {
        return array_map(null, array_column($data['steps'], 'type'), array_column($data['steps'], 'toolName'));
    }

    private function storedStatus(string $uuid): AgentMissionStatus
    {
        $this->em->clear();

        return $this->em->getRepository(AgentMission::class)->findOneBy(['uuid' => $uuid])->getStatus();
    }

    public function testApprovingRunsTheToolOnceAndEndsWaitingForThePublicationApproval(): void
    {
        $uuid = $this->startMission();

        $data = $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['get_sky_countries'], $this->toolRuns);
        self::assertSame('waiting_approval', $data['status']);
        self::assertTrue($data['requiresApproval']);
        self::assertArrayNotHasKey('pendingTool', $data);
        self::assertSame('El misterio', $data['title']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        self::assertSame(220, $data['totalTokens'], 'Both model calls add up.');
        self::assertSame([
            ['started', null],
            ['tool_called', 'get_sky_countries'],
            ['approval_requested', 'get_sky_countries'],
            ['approved', 'get_sky_countries'],
            ['tool_succeeded', 'get_sky_countries'],
            ['tool_called', 'publish_sky_report'],
            ['proposal_created', null],
            ['approval_requested', 'publish_sky_report'],
        ], $this->timeline($data));
        self::assertSame(0, $this->em->getRepository(PaperPlane::class)->count());
    }

    public function testTheContinuationReceivesTheRealResultInTheOriginalConversation(): void
    {
        $this->humans('ES', 3);
        $uuid = $this->startMission();

        $this->decideTool($uuid);

        self::assertCount(2, $this->modelCalls);
        self::assertSame(['get_sky_emojis', 'get_sky_countries', 'get_sky_activity'], $this->modelCalls[0]['tools']);
        self::assertSame(['publish_sky_report'], $this->modelCalls[1]['tools']);

        $messages = array_values($this->modelCalls[1]['input']['messages']);
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($messages, 'role'));
        self::assertSame('call_research', $messages[2]['tool_calls'][0]['id']);
        self::assertSame('get_sky_countries', $messages[2]['tool_calls'][0]['function']['name']);
        self::assertSame('call_research', $messages[3]['tool_call_id']);
        self::assertStringContainsString('ES', $messages[3]['content']);
        self::assertStringNotContainsString(ToolApprovalListener::AWAITING_APPROVAL, (string) json_encode($messages));
    }

    public function testRejectingNeverRunsTheToolNorCallsTheModelAgain(): void
    {
        $uuid = $this->startMission();

        $data = $this->decideTool($uuid, '{"accepted": false}');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('rejected', $data['status']);
        self::assertFalse($data['requiresApproval']);
        self::assertSame([], $this->toolRuns);
        self::assertCount(1, $this->modelCalls);
        self::assertSame([
            ['started', null],
            ['tool_called', 'get_sky_countries'],
            ['approval_requested', 'get_sky_countries'],
            ['rejected', 'get_sky_countries'],
        ], $this->timeline($data));
    }

    public function testAnotherHumanCanDecide(): void
    {
        $uuid = $this->startMission();

        $this->client->loginUser($this->human(), 'main');
        $data = $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('waiting_approval', $data['status']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function secondDecisions(): iterable
    {
        yield 'approve twice' => ['{"accepted": true}', '{"accepted": true}', 'This tool has already been decided.'];
        yield 'reject an approved one' => ['{"accepted": true}', '{"accepted": false}', 'This tool has already been decided.'];
        yield 'approve a rejected one' => ['{"accepted": false}', '{"accepted": true}', 'This mission has already been rejected.'];
    }

    #[DataProvider('secondDecisions')]
    public function testASecondDecisionChangesNothing(string $first, string $second, string $message): void
    {
        $uuid = $this->startMission();
        $before = $this->decideTool($uuid, $first);
        $toolRuns = $this->toolRuns;
        $modelCalls = \count($this->modelCalls);

        $data = $this->decideTool($uuid, $second);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($message, $data['violations'][0]['message']);
        self::assertSame($toolRuns, $this->toolRuns);
        self::assertCount($modelCalls, $this->modelCalls);
        self::assertSame($before['status'], $this->storedStatus($uuid)->value);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing' => ['{}', Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'null' => ['{"accepted": null}', Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'not a boolean' => ['{"accepted": "yes"}', Response::HTTP_BAD_REQUEST];
    }

    #[DataProvider('invalidBodies')]
    public function testAcceptedIsARequiredBoolean(string $body, int $status): void
    {
        $uuid = $this->startMission();

        $this->decideTool($uuid, $body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame([], $this->toolRuns);
        self::assertSame(AgentMissionStatus::WaitingToolApproval, $this->storedStatus($uuid));
    }

    public function testTheBodyCannotChangeTheTool(): void
    {
        $uuid = $this->startMission();

        $this->decideTool($uuid, '{"accepted": true, "pendingToolName": "get_sky_emojis", "name": "get_sky_emojis", "arguments": {"a": 1}, "result": "x"}');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['get_sky_countries'], $this->toolRuns);
    }

    public function testAnonymousCannotDecide(): void
    {
        $uuid = $this->startMission();
        $this->client->getCookieJar()->clear();

        $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->toolRuns);
    }

    public function testBotSessionCannotDecide(): void
    {
        $uuid = $this->startMission();
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot'), 'main');

        $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->toolRuns);
    }

    /** The web firewall does not accept bot codes: rejected before reaching the operation. */
    public function testBotBearerCannotDecide(): void
    {
        $uuid = $this->startMission();
        $this->client->getCookieJar()->clear();
        // A distinct origin, so the persisted Bearer failure limiter never leaks between runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot')->getCode());

        $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([], $this->toolRuns);
    }

    public function testAnUnknownMissionIsNotFound(): void
    {
        $this->client->loginUser($this->human(), 'main');

        $this->decideTool('0198f1a2-7b3c-7d4e-8f50-123456789abc');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAToolThatFailsEndsTheMissionWithoutCallingTheModel(): void
    {
        $uuid = $this->startMission();
        static::getContainer()->get('event_dispatcher')->addListener(ToolCallArgumentsResolved::class, static function (): never {
            throw new \RuntimeException('Tool down');
        }, 100);

        $data = $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('failed', $data['status']);
        self::assertSame(
            [['approved', 'get_sky_countries'], ['tool_failed', 'get_sky_countries'], ['failed', null]],
            \array_slice($this->timeline($data), -3),
        );
        self::assertCount(1, $this->modelCalls);
    }

    public function testTheWholeFlowPublishesOnePlaneAsSkyPulseWithoutAnotherModelCall(): void
    {
        $uuid = $this->startMission();
        $this->decideTool($uuid);
        $modelCalls = \count($this->modelCalls);

        $data = $this->post(sprintf('/api/agent_missions/%s/decision', $uuid), '{"accepted": true}');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('completed', $data['status']);
        self::assertCount($modelCalls, $this->modelCalls);
        self::assertSame(['get_sky_countries'], $this->toolRuns);
        self::assertSame([
            ['started', null],
            ['tool_called', 'get_sky_countries'],
            ['approval_requested', 'get_sky_countries'],
            ['approved', 'get_sky_countries'],
            ['tool_succeeded', 'get_sky_countries'],
            ['tool_called', 'publish_sky_report'],
            ['proposal_created', null],
            ['approval_requested', 'publish_sky_report'],
            ['approved', 'publish_sky_report'],
            ['completed', null],
        ], $this->timeline($data));

        $planes = $this->em->getRepository(PaperPlane::class)->findAll();
        self::assertCount(1, $planes);
        self::assertSame('Sky Pulse', $planes[0]->getCreatedBy()->getName());
        self::assertSame($planes[0]->getUuid()->toRfc4122(), $data['publishedPlane']);
    }

    /** @return array<string, mixed> */
    private function publicGet(string $uuid): array
    {
        $this->client->getCookieJar()->clear();
        $this->em->clear(); // what is stored, not what this request has in memory
        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function stepsWithResult(array $data): array
    {
        return array_values(array_filter($data['steps'], static fn (array $step): bool => \array_key_exists('result', $step)));
    }

    private function humans(string $country, int $total): void
    {
        for ($i = 0; $i < $total; ++$i) {
            static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Humana', $country);
        }
    }

    /** @return list<array{countryCode: string, total: int}> ES as startMission() and these humans leave it */
    private function countries(): array
    {
        $this->humans('ES', 3);
        $this->humans('FR', 3);

        return [['countryCode' => 'FR', 'total' => 3], ['countryCode' => 'ES', 'total' => 4]];
    }

    /** @param list<array<string, mixed>> $result */
    private function assertSameCountries(array $expected, array $result): void
    {
        usort($expected, static fn (array $a, array $b): int => $a['countryCode'] <=> $b['countryCode']);
        usort($result, static fn (array $a, array $b): int => $a['countryCode'] <=> $b['countryCode']);
        self::assertSame($expected, $result);
    }

    public function testAnonymousComparesTheRealResultWithTheProposal(): void
    {
        $expected = $this->countries();
        $uuid = $this->startMission();
        $decided = $this->decideTool($uuid);

        $this->assertSameCountries($expected, $this->stepsWithResult($decided)[0]['result']);
        $data = $this->publicGet($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        // The proposal...
        self::assertSame('waiting_approval', $data['status']);
        self::assertTrue($data['requiresApproval']);
        self::assertSame('El misterio', $data['title']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        // ...and the data it comes from, within a timeline in order.
        self::assertSame(range(1, 8), array_column($data['steps'], 'sequence'));
        $evidence = $this->stepsWithResult($data);
        self::assertCount(1, $evidence);
        self::assertSame('tool_succeeded', $evidence[0]['type']);
        self::assertSame('get_sky_countries', $evidence[0]['toolName']);
        $this->assertSameCountries($expected, $evidence[0]['result']);
        self::assertSame($this->stepsWithResult($decided), $evidence, 'The POST already gave what the GET gives.');
        self::assertNotEmpty($evidence[0]['createdAt']);
        self::assertSame(['get_sky_countries'], $this->toolRuns, 'Reading does not run the tool again.');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('call_research', $content);
        self::assertStringNotContainsString('agent_context', $content);
        self::assertStringNotContainsString('agentContext', $content);
        self::assertStringNotContainsString('Explore FlyNotes', $content);
        self::assertStringNotContainsString(ToolApprovalListener::AWAITING_APPROVAL, $content);
    }

    public function testTheToolResultSurvivesAFailingModel(): void
    {
        $expected = $this->countries();
        $uuid = $this->startMission();
        $this->continuationFails = true;

        $decided = $this->decideTool($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('failed', $decided['status']);

        $data = $this->publicGet($uuid);

        self::assertSame('failed', $data['status']);
        self::assertNull($data['proposedMessage']);
        $this->assertSameCountries($expected, $this->stepsWithResult($data)[0]['result']);
        self::assertSame($this->stepsWithResult($decided), $this->stepsWithResult($data));
        self::assertSame(
            [['approved', 'get_sky_countries'], ['tool_succeeded', 'get_sky_countries'], ['failed', null]],
            \array_slice($this->timeline($data), -3),
        );
    }

    public function testNeitherARejectedNorAFailingToolLeavesAResult(): void
    {
        $rejected = $this->startMission();
        $this->decideTool($rejected, '{"accepted": false}');
        self::assertSame([], $this->stepsWithResult($this->publicGet($rejected)));

        $this->modelCalls = [];
        $failing = $this->startMission();
        static::getContainer()->get('event_dispatcher')->addListener(ToolCallArgumentsResolved::class, static function (): never {
            throw new \RuntimeException('Tool down');
        }, 100);
        $this->decideTool($failing);

        $data = $this->publicGet($failing);

        self::assertSame('failed', $data['status']);
        self::assertSame([], $this->stepsWithResult($data));
        self::assertNotContains('tool_succeeded', array_column($data['steps'], 'type'));
    }

    /** What the human reads is what the model read and what is stored: every country, with its real count. */
    public function testEveryCountryReachesEverywhereTheResultGoes(): void
    {
        $this->humans('ES', 3); // and one more when the mission starts
        $this->humans('FR', 3);
        $this->humans('IT', 2);
        $this->humans('DE', 1);
        $uuid = $this->startMission();

        $decided = $this->decideTool($uuid);

        $expected = [['countryCode' => 'ES', 'total' => 4], ['countryCode' => 'FR', 'total' => 3], ['countryCode' => 'IT', 'total' => 2], ['countryCode' => 'DE', 'total' => 1]];
        $this->assertSameCountries($expected, $this->stepsWithResult($decided)[0]['result']);

        $messages = array_values($this->modelCalls[1]['input']['messages']);
        $this->assertSameCountries($expected, json_decode($messages[3]['content'], true));

        $this->assertSameCountries($expected, $this->stepsWithResult($this->publicGet($uuid))[0]['result']);
    }

    /** A mission already past its research, with results no real tool returns today. */
    private function missionWithResults(): AgentMission
    {
        $mission = new AgentMission();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_activity', ['result' => ['planes' => 7]]);
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_emojis', ['result' => []]);
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_activity', ['result' => null]);
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_countries');

        return $mission;
    }

    private function assertResponseKeepsTheResults(): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        $steps = json_decode($content, true)['steps'];

        self::assertStringContainsString('"result":{"planes":7}', $content);
        self::assertStringContainsString('"result":[]', $content);
        self::assertNull($steps[2]['result']);
        self::assertArrayHasKey('result', $steps[2]);
        self::assertArrayNotHasKey('result', $steps[3]);
    }

    public function testBothDecisionsAnswerWithTheSameRepresentationOfTheResults(): void
    {
        $this->client->loginUser($this->human(), 'main');

        $tool = $this->missionWithResults();
        $tool->requestToolApproval('call_1', 'get_sky_countries', [], ['messages' => []]);
        $publication = $this->missionWithResults();
        $publication->requestApproval('El misterio', self::MESSAGE);
        $this->em->persist($tool);
        $this->em->persist($publication);
        $this->em->flush();
        $this->em->clear();

        $this->decideTool($tool->getUuid()->toRfc4122(), '{"accepted": false}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertResponseKeepsTheResults();

        $this->post(sprintf('/api/agent_missions/%s/decision', $publication->getUuid()->toRfc4122()), '{"accepted": false}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertResponseKeepsTheResults();
    }

    /** Text that cannot be JSON would break the flush and close the entity manager, ending in a 500. */
    public function testAnInvalidResultEndsInAFailedMissionAndNotInAServerError(): void
    {
        $tool = new #[AsTool('get_sky_countries', 'Returns text that is not UTF-8.')] class {
            public function __invoke(): string
            {
                return "caf\xE9";
            }
        };
        static::getContainer()->set('ai.toolbox.sky_pulse', new Toolbox([$tool], eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $toolCall = new ToolCall('call_research', 'get_sky_countries');
        $mission = new AgentMission();
        $mission->requestToolApproval($toolCall->getId(), $toolCall->getName(), [], static::getContainer()->get(NormalizerInterface::class)->normalize(
            new MessageBag(Message::ofUser('Explore FlyNotes.'), Message::ofAssistant($toolCall)),
        ));
        $this->em->persist($mission);
        $this->em->flush();
        $this->client->loginUser($this->human(), 'main');

        $data = $this->decideTool($mission->getUuid()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('failed', $data['status']);
        self::assertSame([['approval_requested', 'get_sky_countries'], ['approved', 'get_sky_countries'], ['tool_failed', 'get_sky_countries'], ['failed', null]], $this->timeline($data));
        self::assertSame([], $this->modelCalls);
        self::assertNull($data['proposedMessage'] ?? null);
        self::assertSame(0, $this->em->getRepository(PaperPlane::class)->count());
    }

    public function testTheEvidenceStaysAfterTheFinalApprovalPublishesOnePlane(): void
    {
        $expected = $this->countries();
        $uuid = $this->startMission();
        $decided = $this->decideTool($uuid);

        $approved = $this->post(sprintf('/api/agent_missions/%s/decision', $uuid), '{"accepted": true}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($this->stepsWithResult($decided), $this->stepsWithResult($approved));

        $data = $this->publicGet($uuid);

        self::assertSame('completed', $data['status']);
        self::assertSame($approved['publishedPlane'], $data['publishedPlane']);
        $this->assertSameCountries($expected, $this->stepsWithResult($data)[0]['result']);
        self::assertSame(['get_sky_countries'], $this->toolRuns);
        self::assertSame(1, $this->em->getRepository(PaperPlane::class)->count());
    }

    public function testRejectingTheReportKeepsBothPhasesInTheTimeline(): void
    {
        $uuid = $this->startMission();
        $this->decideTool($uuid);

        $data = $this->post(sprintf('/api/agent_missions/%s/decision', $uuid), '{"accepted": false}');

        self::assertSame('rejected', $data['status']);
        self::assertSame(
            [['approval_requested', 'publish_sky_report'], ['rejected', 'publish_sky_report']],
            \array_slice($this->timeline($data), -2),
        );
        self::assertSame(0, $this->em->getRepository(PaperPlane::class)->count());
    }
}
