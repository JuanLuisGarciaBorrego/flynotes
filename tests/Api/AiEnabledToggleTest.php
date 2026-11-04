<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\Enum\AgentMissionStatus;
use App\Enum\AiMessageStatus;
use App\Enum\ParticipantType;
use App\Exception\AiUnavailableException;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use App\Repository\AgentMissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI_ENABLED and what a visitor gets when the provider fails, over HTTP. The switch is read from the environment by the
 * real services, as in production. The model is scripted, or the HTTP client below it: nothing reaches DeepSeek.
 */
class AiEnabledToggleTest extends WebTestCase
{
    private const string MESSAGE = 'Hoy ha sido un día complicado.';
    private const string REPORT = 'Siete aviones vuelan por el cielo.';
    private const string SECRET = 'sk-this-must-never-leave-the-server';
    private const string MCP_PROTOCOL_VERSION = '2026-07-28';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $modelCalls = 0;
    private int $toolRuns = 0;
    private int $providerRequests = 0;
    private ?string $previousSwitch = null;

    private function boot(bool $enabled): void
    {
        $this->previousSwitch = $_ENV['AI_ENABLED'] ?? null;
        $_ENV['AI_ENABLED'] = $enabled ? '1' : '0';

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->client->loginUser(static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Autora', 'ES'), 'main');

        static::getContainer()->get('event_dispatcher')->addListener(ToolCallSucceeded::class, function (): void {
            ++$this->toolRuns;
        });
    }

    private function scriptTheModel(): void
    {
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input, array $options): ResultInterface|string {
            ++$this->modelCalls;

            if (!isset($options['tools'])) {
                return '{"text":"Versión de la IA","tone":"Tierno"}';
            }

            return 1 === $this->modelCalls
                ? new ToolCallResult([new ToolCall('call_1', 'get_sky_countries')])
                : new ToolCallResult([new ToolCall('call_2', 'publish_sky_report', ['title' => 'El misterio', 'message' => self::REPORT])]);
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();

        if (null === $this->previousSwitch) {
            unset($_ENV['AI_ENABLED']);
        } else {
            $_ENV['AI_ENABLED'] = $this->previousSwitch;
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body = []): array
    {
        $this->client->request('POST', $path, server: ParticipantApiTest::jsonLd(), content: json_encode((object) $body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful($path);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function missionWaitingForItsTool(): AgentMission
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_countries', [], ['messages' => []]);
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    private function missionWaitingForItsPublication(): AgentMission
    {
        $mission = new AgentMission();
        $mission->requestApproval('El misterio', self::REPORT);
        $this->em->persist($mission);
        $this->em->flush();

        return $mission;
    }

    private function missionPath(AgentMission $mission, string $action = ''): string
    {
        return '/api/agent_missions/'.$mission->getUuid()->toRfc4122().$action;
    }

    private function planes(): int
    {
        return $this->em->getRepository(PaperPlane::class)->count();
    }

    private function assertAiUnavailable(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(AiUnavailableException::MESSAGE, json_decode((string) $this->client->getResponse()->getContent(), true)['detail']);
    }

    public function testDisabledAMissionCannotStartAndNothingIsCreated(): void
    {
        $this->boot(false);
        $this->scriptTheModel();

        $this->post('/api/agent_missions');

        $this->assertAiUnavailable();
        self::assertSame(0, $this->modelCalls);
        self::assertSame(0, $this->em->getRepository(AgentMission::class)->count());
    }

    /** Neither the tool nor the model run, the approval is not recorded, and the humans can still end the mission. */
    public function testDisabledAMissionCannotContinueAndItsStateDoesNotChange(): void
    {
        $this->boot(false);
        $this->scriptTheModel();
        $mission = $this->missionWaitingForItsTool();

        $this->post($this->missionPath($mission, '/tool-decision'), ['accepted' => true]);

        $this->assertAiUnavailable();
        self::assertSame(0, $this->modelCalls);
        self::assertSame(0, $this->toolRuns);
        $this->em->refresh($mission);
        self::assertSame(AgentMissionStatus::WaitingToolApproval, $mission->getStatus());
        self::assertFalse(static::getContainer()->get(AgentMissionRepository::class)->isToolCallApproved('call_1', 'get_sky_countries'));

        $rejected = $this->post($this->missionPath($mission, '/tool-decision'), ['accepted' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $rejected['status']);
        self::assertSame(0, $this->modelCalls);
    }

    public function testDisabledAProposalCanStillBePublishedOrRejectedWithoutTheModel(): void
    {
        $this->boot(false);
        $this->scriptTheModel();

        $publishable = $this->missionWaitingForItsPublication();
        $planes = $this->planes();
        $completed = $this->post($this->missionPath($publishable, '/decision'), ['accepted' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame('completed', $completed['status']);
        self::assertNotNull($completed['publishedPlane']);
        self::assertSame($planes + 1, $this->planes());

        $rejectable = $this->missionWaitingForItsPublication();
        $rejected = $this->post($this->missionPath($rejectable, '/decision'), ['accepted' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $rejected['status']);
        self::assertSame($planes + 1, $this->planes(), 'Nothing is published without a human saying so.');
        self::assertSame(0, $this->modelCalls);
    }

    /** Disabling authorizes nothing: what was waiting for a human keeps waiting. */
    public function testDisabledAPublicationIsNotApprovedByItself(): void
    {
        $this->boot(false);
        $this->scriptTheModel();
        $mission = $this->missionWaitingForItsPublication();
        $planes = $this->planes();

        $read = $this->get($this->missionPath($mission));

        self::assertSame('waiting_approval', $read['status']);
        self::assertTrue($read['requiresApproval']);
        self::assertSame($planes, $this->planes());
    }

    /** Humans keep flying: the plane is published, with no proposal and no generation spent. */
    public function testDisabledAPlaneFliesWithoutAProposalAndCannotAskForOne(): void
    {
        $this->boot(false);
        $this->scriptTheModel();

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::MESSAGE, $plane['message']);
        self::assertNull($plane['aiStatus'] ?? null);
        self::assertNull($plane['aiMessage'] ?? null);
        self::assertSame(0, $plane['aiAttempts']);

        $this->post('/api/paper_planes/'.$plane['uuid'].'/ai-message/retry');

        $this->assertAiUnavailable();
        self::assertSame(0, $this->modelCalls);
        self::assertSame(0, $this->get('/api/paper_planes/'.$plane['uuid'])['aiAttempts']);
    }

    /** Ownership is still answered first: the switch reveals nothing about someone else's plane. */
    public function testDisabledARetryOnAnotherAuthorsPlaneIsStillForbidden(): void
    {
        $this->boot(false);
        $other = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Otra', 'FR');
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($other, self::MESSAGE);

        $this->post('/api/paper_planes/'.$plane->getUuid()->toRfc4122().'/ai-message/retry');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDisabledAPendingProposalCanStillBeDecidedAndStaysPendingUntilThen(): void
    {
        $this->boot(false);
        $this->scriptTheModel();
        $author = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Otra', 'FR');
        $this->client->loginUser($author, 'main');
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($author, self::MESSAGE);
        $plane->proposeAiMessage('Versión de la IA', 'Tierno');
        $this->em->flush();
        $uuid = $plane->getUuid()->toRfc4122();

        self::assertSame(AiMessageStatus::Pending->value, $this->get('/api/paper_planes/'.$uuid)['aiStatus']);

        $this->post('/api/paper_planes/'.$uuid.'/ai-message/retry');
        $this->assertAiUnavailable();
        self::assertSame(AiMessageStatus::Pending->value, $this->get('/api/paper_planes/'.$uuid)['aiStatus'], 'A refused retry approves nothing.');

        $versionId = $this->get('/api/paper_planes/'.$uuid)['aiVersionId'];
        $accepted = $this->post('/api/paper_planes/'.$uuid.'/ai-message/decision', ['accepted' => true, 'versionId' => $versionId]);

        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $accepted['aiStatus']);
        self::assertSame('Versión de la IA', $accepted['aiMessage']);
        self::assertSame(0, $this->modelCalls);
    }

    public function testDisabledEverythingStillReads(): void
    {
        $this->boot(false);
        $this->scriptTheModel();
        $mission = $this->missionWaitingForItsTool();
        $author = static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Human, 'Otra', 'FR');
        $plane = static::getContainer()->get(PaperPlaneManager::class)->create($author, self::MESSAGE);
        $plane->proposeAiMessage('Versión de la IA', 'Tierno');
        $plane->acceptAiVersion($plane->getCurrentAiVersion());
        $this->em->flush();
        $uuid = $plane->getUuid()->toRfc4122();

        self::assertSame($mission->getUuid()->toRfc4122(), $this->get($this->missionPath($mission))['uuid']);
        self::assertSame($mission->getUuid()->toRfc4122(), $this->get('/api/agent_missions/current')['uuid']);
        self::assertSame('Versión de la IA', $this->get('/api/paper_planes/'.$uuid)['aiMessage']);
        self::assertNotEmpty($this->get('/api/paper_planes')['member']);

        foreach (['/sky-pulse', '/'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSame(0, $this->modelCalls);
    }

    /** External agents keep flying, and read why they cannot ask for a version. */
    public function testDisabledAnMcpAgentStillLaunchesAndIsToldWhyItCannotRetry(): void
    {
        $this->boot(false);
        $this->scriptTheModel();
        $bearer = 'Bearer '.static::getContainer()->get(ParticipantManager::class)->create(ParticipantType::Bot, 'Bot', 'ES')->getCode();

        $launched = $this->mcpTool('launch_paper_plane', $bearer, ['message' => self::MESSAGE])['result'];

        self::assertFalse($launched['isError'] ?? false);
        self::assertSame(self::MESSAGE, $launched['structuredContent']['message']);
        self::assertNull($launched['structuredContent']['aiStatus']);

        $retried = $this->mcpTool('retry_paper_plane_ai', $bearer, ['uuid' => $launched['structuredContent']['uuid']])['result'];

        self::assertTrue($retried['isError']);
        self::assertSame(AiUnavailableException::MESSAGE, $retried['content'][0]['text']);
        self::assertSame(0, $this->modelCalls);
    }

    public function testEnabledBothFlowsWorkAsAlways(): void
    {
        $this->boot(true);
        $this->scriptTheModel();

        $mission = $this->post('/api/agent_missions');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('waiting_tool_approval', $mission['status']);

        $proposal = $this->post('/api/agent_missions/'.$mission['uuid'].'/tool-decision', ['accepted' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('waiting_approval', $proposal['status']);
        self::assertSame(1, $this->toolRuns);
        self::assertSame(2, $this->modelCalls);

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('pending', $plane['aiStatus']);
        self::assertSame('Versión de la IA', $plane['aiMessage']);

        $retried = $this->post('/api/paper_planes/'.$plane['uuid'].'/ai-message/retry');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $retried['aiAttempts']);
    }

    /** @return iterable<string, array{\Closure(): MockResponse}> */
    public static function providerFailures(): iterable
    {
        $problem = static fn (int $status, string $reason): \Closure => static fn (): MockResponse => new MockResponse(
            json_encode(['error' => ['message' => $reason.' for '.self::SECRET, 'type' => 'error']], \JSON_THROW_ON_ERROR),
            ['http_code' => $status],
        );

        yield 'insufficient credits' => [$problem(402, 'Insufficient Balance')];
        yield 'invalid key' => [$problem(401, 'Authentication Fails')];
        yield 'rate limit' => [$problem(429, 'Rate Limit Reached')];
        yield 'provider error' => [$problem(500, 'Server Error')];
        yield 'provider overloaded' => [$problem(503, 'Server Overloaded')];
        yield 'network failure' => [static fn (): MockResponse => new MockResponse(info: ['error' => 'Could not resolve host '.self::SECRET])];
        yield 'timeout' => [static fn (): MockResponse => new MockResponse(['', ''], ['error' => 'Idle timeout reached '.self::SECRET])];
        yield 'not JSON at all' => [static fn (): MockResponse => new MockResponse('<html>'.self::SECRET.'</html>', ['http_code' => 200])];
    }

    /**
     * The real platform and converter over a scripted HTTP transport. Its own counter is reset between requests.
     *
     * @param \Closure(): MockResponse $failure
     */
    private function providerFailingWith(\Closure $failure): void
    {
        static::getContainer()->set('http_client.transport', new MockHttpClient(function () use ($failure): MockResponse {
            ++$this->providerRequests;

            return $failure();
        }));
    }

    /** @param \Closure(): MockResponse $failure */
    #[DataProvider('providerFailures')]
    public function testAProviderFailureOnARetryIsAControlledAnswerWithNothingTechnical(\Closure $failure): void
    {
        $this->boot(true);
        $this->providerFailingWith($failure);

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(self::MESSAGE, $plane['message']);
        self::assertNull($plane['aiStatus'] ?? null);
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->providerRequests, 'A failure is not retried.');

        $this->post('/api/paper_planes/'.$plane['uuid'].'/ai-message/retry');

        $this->assertAiUnavailable();
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->providerRequests, 'A failure is not retried.');
        self::assertSame(0, $this->get('/api/paper_planes/'.$plane['uuid'])['aiAttempts'], 'A failure spends nothing.');
    }

    /** @param \Closure(): MockResponse $failure */
    #[DataProvider('providerFailures')]
    public function testAProviderFailureEndsTheMissionWithNothingProposedOrPublished(\Closure $failure): void
    {
        $this->boot(true);
        $this->providerFailingWith($failure);
        $planes = $this->planes();

        $mission = $this->post('/api/agent_missions');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('failed', $mission['status']);
        self::assertNull($mission['proposedMessage'] ?? null);
        self::assertStringNotContainsString(self::SECRET, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->providerRequests, 'A failure is not retried.');
        self::assertSame($planes, $this->planes());
        self::assertSame('failed', $this->get('/api/agent_missions/'.$mission['uuid'])['status']);
    }

    public function testAnInvalidAnswerFromTheModelIsNotPublishedAndEndsInAControlledAnswer(): void
    {
        $this->boot(true);
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            ++$this->modelCalls;

            return 'This is not the JSON you asked for.';
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($plane['aiStatus'] ?? null);
        self::assertSame(2, $this->modelCalls, 'The answer and its only correction.');

        $this->post('/api/paper_planes/'.$plane['uuid'].'/ai-message/retry');

        $this->assertAiUnavailable();
        self::assertSame(4, $this->modelCalls, 'The answer and its only correction.');
    }

    public function testAnInvalidAnswerIsCorrectedOnce(): void
    {
        $this->boot(true);
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            return 1 === ++$this->modelCalls ? 'This is not the JSON you asked for.' : '{"text":"Versión de la IA","tone":"Tierno"}';
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);

        self::assertSame('pending', $plane['aiStatus']);
        self::assertSame('Versión de la IA', $plane['aiMessage']);
        self::assertSame(2, $this->modelCalls);
    }

    /** The real converter: a tool call whose arguments are not JSON, as the provider would send it, then a good one. */
    public function testMalformedToolArgumentsFromTheProviderAreCorrectedOnce(): void
    {
        $this->boot(true);
        static::getContainer()->set('http_client.transport', new MockHttpClient(function (): MockResponse {
            $arguments = 1 === ++$this->providerRequests ? '{not json' : '{}';

            return new MockResponse(json_encode(['choices' => [['message' => [
                'role' => 'assistant',
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_sky_countries', 'arguments' => $arguments]]],
            ], 'finish_reason' => 'tool_calls']]], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type: application/json']]);
        }));

        $mission = $this->post('/api/agent_missions');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('waiting_tool_approval', $mission['status']);
        self::assertSame(2, $this->providerRequests);
    }

    public function testAProviderFailureDuringACorrectionIsNotRetried(): void
    {
        $this->boot(true);
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): string {
            return 1 === ++$this->modelCalls ? 'This is not the JSON you asked for.' : throw new \RuntimeException('Provider down');
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $plane = $this->post('/api/paper_planes', ['message' => self::MESSAGE]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($plane['aiStatus'] ?? null);
        self::assertSame(2, $this->modelCalls);
    }

    /** Workers reuse the services between requests: one mission using its correction does not spend the next one's. */
    public function testEveryMissionHasItsOwnCorrectionInTheSameWorker(): void
    {
        $this->boot(true);
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): ResultInterface {
            return match (++$this->modelCalls % 3) {
                1 => new ToolCallResult([new ToolCall('call_research', 'get_sky_countries')]),
                2 => new ToolCallResult([new ToolCall('call_invalid', 'publish_sky_report', ['title' => '   ', 'message' => self::REPORT])]),
                default => new ToolCallResult([new ToolCall('call_valid', 'publish_sky_report', ['title' => 'El misterio', 'message' => self::REPORT])]),
            };
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        foreach ([1, 2] as $run) {
            $mission = $this->post('/api/agent_missions');
            $proposal = $this->post('/api/agent_missions/'.$mission['uuid'].'/tool-decision', ['accepted' => true]);

            self::assertSame('waiting_approval', $proposal['status'], 'Mission '.$run);
            self::assertSame(3 * $run, $this->modelCalls);
            $this->post('/api/agent_missions/'.$mission['uuid'].'/decision', ['accepted' => false]);
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function mcpTool(string $name, string $bearer, array $arguments): array
    {
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
            'HTTP_MCP_METHOD' => 'tools/call',
            'HTTP_MCP_NAME' => $name,
            'HTTP_AUTHORIZATION' => $bearer,
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => (object) $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MCP_PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
