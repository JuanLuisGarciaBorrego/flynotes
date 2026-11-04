<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\AgentMissionStep;
use App\Entity\PlaneVisit;
use App\Enum\AgentMissionStepType;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/agent_missions/{uuid}: public and read-only. */
class AgentMissionGetApiTest extends WebTestCase
{
    private const string MESSAGE = 'Siete aviones vuelan por el cielo.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $modelCalls = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            ++$this->modelCalls;

            return 'Never asked.';
        }));
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function store(AgentMission $mission = new AgentMission()): AgentMission
    {
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    private function waitingMission(): AgentMission
    {
        $mission = new AgentMission();
        $mission->requestApproval('El misterio', self::MESSAGE);

        return $this->store($mission);
    }

    /** @return array<string, mixed> */
    private function get(AgentMission|string $mission): array
    {
        $uuid = $mission instanceof AgentMission ? $mission->getUuid()->toRfc4122() : $mission;
        $this->em->clear(); // what is stored, not what this test has in memory
        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testAnonymousReadsAWaitingMission(): void
    {
        $mission = $this->waitingMission();
        $mission->addTokenUsage(1000, 676);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['uuid', 'status', 'createdAt', 'title', 'proposedMessage', 'requiresApproval', 'totalTokens', 'pendingTool', 'publishedPlane', 'steps'], array_keys($data));
        self::assertSame($mission->getUuid()->toRfc4122(), $data['uuid']);
        self::assertSame('waiting_approval', $data['status']);
        self::assertSame($mission->getCreatedAt()?->format(\DATE_ATOM), $data['createdAt']);
        self::assertSame('El misterio', $data['title']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        self::assertTrue($data['requiresApproval']);
        self::assertSame(1676, $data['totalTokens']);
        self::assertNull($data['publishedPlane']);
        self::assertNull($data['pendingTool']);
    }

    public function testAMissionWaitingForItsToolShowsItButNotTheAgentContext(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_secret', 'get_sky_countries', [], ['messages' => 'internal conversation']);

        $data = $this->get($this->store($mission));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('waiting_tool_approval', $data['status']);
        self::assertTrue($data['requiresApproval']);
        self::assertSame('get_sky_countries', $data['pendingTool']['name']);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"arguments":{}', $content);
        self::assertStringNotContainsString('internal conversation', $content);
        self::assertStringNotContainsString('call_secret', $content);
    }

    /** @return iterable<string, array{ParticipantType}> */
    public static function sessions(): iterable
    {
        yield 'human' => [ParticipantType::Human];
        yield 'bot' => [ParticipantType::Bot];
    }

    #[DataProvider('sessions')]
    public function testAnyIdentifiedParticipantCanReadIt(ParticipantType $type): void
    {
        $mission = $this->waitingMission();
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create($type, 'Test', 'ES'), 'main');

        $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /** Firewall rule, kept as it is: a credential the web firewall rejects never falls back to anonymous. */
    public function testABotSendingItsMcpCredentialIsStillRejected(): void
    {
        $mission = $this->waitingMission();
        // A distinct origin, so the persisted Bearer failure limiter never leaks between runs.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot')->getCode());

        $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** A running mission also proves MissionAvailableToStart does not apply here: it would answer 422. */
    public function testARunningMissionShowsWhatItHasSoFar(): void
    {
        $mission = new AgentMission();
        $mission->addStep(AgentMissionStepType::Started);
        $mission->addStep(AgentMissionStepType::ToolCalled, 'get_sky_emojis');

        $data = $this->get($this->store($mission));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('running', $data['status']);
        self::assertFalse($data['requiresApproval']);
        self::assertNull($data['title']);
        self::assertNull($data['proposedMessage']);
        self::assertSame(0, $data['totalTokens']);
        self::assertSame(
            [['started', null], ['tool_called', 'get_sky_emojis']],
            array_map(null, array_column($data['steps'], 'type'), array_column($data['steps'], 'toolName')),
        );
    }

    public function testACompletedMissionShowsItsPlaneWithoutCountingAVisit(): void
    {
        $participants = static::getContainer()->get(ParticipantManager::class);
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($participants->getSkyPulse(), self::MESSAGE);
        $mission = $this->waitingMission();
        $mission->approve($plane);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('completed', $data['status']);
        self::assertFalse($data['requiresApproval']);
        self::assertSame($plane->getUuid()->toRfc4122(), $data['publishedPlane']);
        self::assertSame(0, $this->em->getRepository(PlaneVisit::class)->count());
    }

    public function testARejectedMissionKeepsItsProposal(): void
    {
        $mission = $this->waitingMission();
        $mission->reject();
        $this->em->flush();

        $data = $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('rejected', $data['status']);
        self::assertFalse($data['requiresApproval']);
        self::assertSame(self::MESSAGE, $data['proposedMessage']);
        self::assertNull($data['publishedPlane']);
    }

    public function testAFailedMissionCanBeRead(): void
    {
        $mission = new AgentMission();
        $mission->fail();

        $data = $this->get($this->store($mission));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('failed', $data['status']);
        self::assertFalse($data['requiresApproval']);
    }

    public function testStepsComeOrderedBySequenceWithoutTheirPayload(): void
    {
        $mission = $this->store();
        // Inserted backwards, so that only the sequence can give the right order.
        foreach ([3 => AgentMissionStepType::Completed, 2 => AgentMissionStepType::ToolCalled, 1 => AgentMissionStepType::Started] as $sequence => $type) {
            $this->em->persist(new AgentMissionStep($mission, $sequence, $type, payload: ['secret' => 'internal']));
            $this->em->flush();
        }

        $data = $this->get($mission);

        self::assertSame([1, 2, 3], array_column($data['steps'], 'sequence'));
        self::assertSame(['started', 'tool_called', 'completed'], array_column($data['steps'], 'type'));
        self::assertSame(['sequence', 'type', 'toolName', 'createdAt'], array_keys($data['steps'][0]));
        self::assertStringNotContainsString('secret', (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function publicResults(): iterable
    {
        yield 'list' => ['get_sky_countries', [['countryCode' => 'ES', 'total' => 40], ['countryCode' => 'FR', 'total' => 18]]];
        yield 'emojis, not escaped into text' => ['get_sky_emojis', [['emoji' => '🦊', 'total' => 3]]];
        yield 'map' => ['get_sky_activity', ['planes' => 7, 'views' => 0, 'stamps' => 2]];
        yield 'text' => ['get_sky_activity', 'Tres cielos.'];
        yield 'empty list' => ['get_sky_countries', []];
        yield 'number' => ['get_sky_activity', 42];
        yield 'boolean' => ['get_sky_activity', false];
        yield 'text that looks like JSON' => ['get_sky_activity', '{"ES": 40}'];
        yield 'no result' => ['get_sky_activity', null];
    }

    #[DataProvider('publicResults')]
    public function testASucceededPublicToolShowsItsResultAsIs(string $tool, mixed $result): void
    {
        $mission = $this->store();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, $tool, ['result' => $result]);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['sequence', 'type', 'toolName', 'result', 'createdAt'], array_keys($data['steps'][0]));
        self::assertSame($tool, $data['steps'][0]['toolName']);
        self::assertSame($result, $data['steps'][0]['result']);
    }

    public function testANullResultIsShownAsNull(): void
    {
        $mission = $this->store();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_activity', ['result' => null]);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertArrayHasKey('result', $data['steps'][0]);
        self::assertNull($data['steps'][0]['result']);
    }

    public function testAToolOutsideThePublicListNeverShowsItsResult(): void
    {
        $mission = $this->store();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_private_data', ['result' => 'Ana, ana@example.com']);
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'publish_sky_report', ['result' => 'internal']);
        $mission->addStep(AgentMissionStepType::ToolSucceeded, null, ['result' => 'internal']);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertSame(['tool_succeeded', 'tool_succeeded', 'tool_succeeded'], array_column($data['steps'], 'type'));
        self::assertSame([], array_column($data['steps'], 'result'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('ana@example.com', $content);
        self::assertStringNotContainsString('internal', $content);
    }

    /** @return iterable<string, array{AgentMissionStepType}> */
    public static function stepsThatAreNotASuccess(): iterable
    {
        foreach (AgentMissionStepType::cases() as $type) {
            if (AgentMissionStepType::ToolSucceeded !== $type) {
                yield $type->value => [$type];
            }
        }
    }

    #[DataProvider('stepsThatAreNotASuccess')]
    public function testNoOtherKindOfStepShowsItsPayload(AgentMissionStepType $type): void
    {
        $mission = $this->store();
        $mission->addStep($type, 'get_sky_countries', ['result' => 'internal', 'reason' => 'internal']);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertSame(['sequence', 'type', 'toolName', 'createdAt'], array_keys($data['steps'][0]));
        self::assertStringNotContainsString('internal', (string) $this->client->getResponse()->getContent());
    }

    public function testOnlyTheResultLeavesThePayload(): void
    {
        $mission = $this->store();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_activity', ['result' => ['planes' => 7], 'authorization' => 'call_secret']);
        $this->em->flush();

        $data = $this->get($mission);

        self::assertSame(['planes' => 7], $data['steps'][0]['result']);
        self::assertStringNotContainsString('call_secret', (string) $this->client->getResponse()->getContent());
    }

    /** Stored before results were recorded: there is nothing to show, which is not the same as a null result. */
    public function testASuccessWithoutAStoredResultHasNoResultKey(): void
    {
        $mission = $this->store();
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_activity');
        $mission->addStep(AgentMissionStepType::ToolSucceeded, 'get_sky_countries', ['other' => 'internal']);
        $this->em->flush();
        $connection = $this->em->getConnection();
        $stored = static fn (): array => $connection->fetchFirstColumn('SELECT payload FROM agent_mission_step ORDER BY sequence');
        $before = $stored();

        $data = $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertArrayNotHasKey('result', $data['steps'][0]);
        self::assertArrayNotHasKey('result', $data['steps'][1]);
        self::assertSame($before, $stored(), 'Old rows stay as they are.');
    }

    /** @return iterable<string, array{string}> */
    public static function missions404(): iterable
    {
        yield 'unknown' => ['0198f1a2-7b3c-7d4e-8f50-123456789abc'];
        yield 'malformed' => ['not-a-uuid'];
    }

    #[DataProvider('missions404')]
    public function testAMissionThatCannotBeFoundIsNotFound(string $uuid): void
    {
        $this->get($uuid);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testReadingChangesNothingAndDoesNotRunTheAgent(): void
    {
        $mission = $this->waitingMission();
        $connection = $this->em->getConnection();
        $snapshot = static fn (): array => [
            $connection->fetchAllAssociative('SELECT * FROM agent_mission'),
            $connection->fetchAllAssociative('SELECT * FROM agent_mission_step ORDER BY id'),
        ];
        $before = $snapshot();

        $this->get($mission);
        $this->get($mission);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($before, $snapshot());
        self::assertSame(0, $this->modelCalls);
    }

    public function testItsJsonLdIdIsThisEndpoint(): void
    {
        $uuid = $this->waitingMission()->getUuid()->toRfc4122();

        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertSame('/api/agent_missions/'.$uuid, json_decode((string) $this->client->getResponse()->getContent(), true)['@id']);
    }
}
