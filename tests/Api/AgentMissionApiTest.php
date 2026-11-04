<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\AgentMissionStatus;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use App\Validator\MissionAvailableToStart;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/agent_missions with the real `sky_pulse` agent, tools included: only its model is scripted. */
class AgentMissionApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $modelCalls = 0;
    private int $toolRuns = 0;
    /** @var list<string> */
    private array $offeredTools = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep the seeded transaction and the scripted model across requests
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input, array $options): ResultInterface {
            ++$this->modelCalls;
            $this->offeredTools = array_column(array_column($options['tools'], 'function'), 'name');

            return new ToolCallResult([new ToolCall('call_1', 'get_sky_countries')]);
        }));
        static::getContainer()->get('event_dispatcher')->addListener(ToolCallSucceeded::class, function (): void {
            ++$this->toolRuns;
        });
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function participant(ParticipantType $type): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, 'Test', 'ES');
    }

    /** @return array<string, mixed> */
    private function start(): array
    {
        $this->client->request('POST', '/api/agent_missions', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(16)),
        ], content: '{}');

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function startAsHuman(): array
    {
        $this->client->loginUser($this->participant(ParticipantType::Human), 'main');

        return $this->start();
    }

    private function storeMission(AgentMissionStatus $status): void
    {
        $mission = new AgentMission();
        (new \ReflectionProperty(AgentMission::class, 'status'))->setValue($mission, $status);
        $this->em->persist($mission);
        $this->em->flush();
    }

    private function assertNoMissionStarted(int $existing = 0): void
    {
        self::assertSame($existing, $this->em->getRepository(AgentMission::class)->count());
        self::assertSame(0, $this->modelCalls);
    }

    public function testHumanStartsAMissionThatEndsWaitingForItsToolWithoutRunningIt(): void
    {
        $data = $this->startAsHuman();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEqualsCanonicalizing(['get_sky_emojis', 'get_sky_countries', 'get_sky_activity'], $this->offeredTools);
        self::assertSame(1, $this->modelCalls, 'No further model round after requesting the tool.');
        self::assertSame(0, $this->toolRuns);

        self::assertSame(['uuid', 'status', 'createdAt', 'requiresApproval', 'totalTokens', 'pendingTool', 'steps'], array_keys($data));
        self::assertSame('waiting_tool_approval', $data['status']);
        self::assertTrue($data['requiresApproval']);
        self::assertSame('get_sky_countries', $data['pendingTool']['name']);
        self::assertStringContainsString('"arguments":{}', (string) $this->client->getResponse()->getContent());
        self::assertSame(
            [['started', null], ['tool_called', 'get_sky_countries'], ['approval_requested', 'get_sky_countries']],
            array_map(null, array_column($data['steps'], 'type'), array_column($data['steps'], 'toolName')),
        );

        $this->em->clear();
        $stored = $this->em->getRepository(AgentMission::class)->findOneBy(['uuid' => $data['uuid']]);
        self::assertSame(AgentMissionStatus::WaitingToolApproval, $stored->getStatus());
        self::assertSame('get_sky_countries', $stored->getPendingToolName());
        self::assertSame([], $stored->getPendingToolArguments());
        self::assertSame($stored->getCreatedAt()?->format(\DATE_ATOM), $data['createdAt']);
        self::assertSame(0, $this->em->getRepository(PaperPlane::class)->count());
    }

    #[DataProvider('activeStatuses')]
    public function testAnActiveMissionIsAValidationError(AgentMissionStatus $status): void
    {
        $this->storeMission($status);

        $data = $this->startAsHuman();

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame((new MissionAvailableToStart())->message, $data['violations'][0]['message']);
        $this->assertNoMissionStarted(existing: 1);
    }

    #[DataProvider('terminalStatuses')]
    public function testATerminalMissionDoesNotBlock(AgentMissionStatus $status): void
    {
        $this->storeMission($status);

        $this->startAsHuman();

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(2, $this->em->getRepository(AgentMission::class)->count());
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $this->start();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertNoMissionStarted();
    }

    public function testBotSessionIsForbidden(): void
    {
        $this->client->loginUser($this->participant(ParticipantType::Bot), 'main');

        $this->start();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertNoMissionStarted();
    }

    /** The web firewall does not accept bot codes: rejected before reaching the operation. */
    public function testBotBearerIsUnauthorized(): void
    {
        // A distinct origin, so the persisted Bearer failure limiter never leaks between runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->participant(ParticipantType::Bot)->getCode());

        $this->start();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertNoMissionStarted();
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
