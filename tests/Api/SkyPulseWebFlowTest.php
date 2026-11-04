<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\PaperPlane;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP contract the Sky Pulse page relies on (assets/js/sky-pulse.js), request by request as the browser sends
 * them: JSON-LD in and out, session cookie and `csrf-token` header. Only the model is scripted.
 */
class SkyPulseWebFlowTest extends WebTestCase
{
    /** An agent's text is not trusted: the API returns it untouched, and only textContent may paint it. */
    private const string TITLE = '<img src=x onerror=alert(1)>';
    private const string MESSAGE = '<script>alert("plane")</script> Vuelan aviones desde ES.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $modelCalls = 0;
    private ?ResultInterface $publication = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->publication = new ToolCallResult([new ToolCall('call_2', 'publish_sky_report', ['title' => self::TITLE, 'message' => self::MESSAGE])]);
        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function (): ResultInterface {
            return 1 === ++$this->modelCalls
                ? new ToolCallResult([new ToolCall('call_1', 'get_sky_countries')])
                : $this->publication ?? throw new \RuntimeException('The model is down.');
        }));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function signIn(): void
    {
        $participants = static::getContainer()->get(ParticipantManager::class);
        for ($i = 0; $i < 3; ++$i) {
            $human = $participants->create(ParticipantType::Human, 'Humana '.$i, 'ES');
        }
        $this->client->loginUser($human, 'main');
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(12)),
        ], content: (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function get(string $uuid): array
    {
        $this->em->clear();
        $this->client->request('GET', '/api/agent_missions/'.$uuid, server: ['HTTP_ACCEPT' => 'application/ld+json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function start(): array
    {
        return $this->post('/api/agent_missions', []);
    }

    /** @return array<string, mixed> */
    private function decideTool(string $uuid, bool $accepted): array
    {
        return $this->post(sprintf('/api/agent_missions/%s/tool-decision', $uuid), ['accepted' => $accepted]);
    }

    /** @return array<string, mixed> */
    private function decidePublication(string $uuid, bool $accepted): array
    {
        return $this->post(sprintf('/api/agent_missions/%s/decision', $uuid), ['accepted' => $accepted]);
    }

    /** @return list<string> */
    private static function types(array $mission): array
    {
        return array_column($mission['steps'], 'type');
    }

    private function planeCount(): int
    {
        return $this->em->getRepository(PaperPlane::class)->count();
    }

    public function testTheWholeFlightFromStartToPublishedPlane(): void
    {
        $this->signIn();

        $waiting = $this->start();
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('waiting_tool_approval', $waiting['status']);
        self::assertSame('get_sky_countries', $waiting['pendingTool']['name']);
        self::assertNotContains('tool_succeeded', self::types($waiting), 'Nothing runs before a human authorizes it.');

        $proposal = $this->decideTool($waiting['uuid'], true);
        self::assertResponseIsSuccessful();
        self::assertSame('waiting_approval', $proposal['status']);
        self::assertSame(self::TITLE, $proposal['title']);
        self::assertSame(self::MESSAGE, $proposal['proposedMessage']);
        $research = $proposal['steps'][array_search('tool_succeeded', self::types($proposal), true)];
        self::assertSame('get_sky_countries', $research['toolName']);
        self::assertContains(['countryCode' => 'ES', 'total' => 3], $research['result']);
        self::assertSame(0, $this->planeCount());

        $completed = $this->decidePublication($waiting['uuid'], true);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $completed['status']);
        self::assertSame(1, $this->planeCount());

        // The link of the confirmation: the published plane can be read by its UUID.
        $this->client->request('GET', '/api/paper_planes/'.$completed['publishedPlane'], server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::MESSAGE, json_decode((string) $this->client->getResponse()->getContent(), true)['message']);

        // A reload brings everything back, and so does the discovery route of a visitor without the UUID.
        $reloaded = $this->get($waiting['uuid']);
        self::assertSame('completed', $reloaded['status']);
        self::assertSame($completed['publishedPlane'], $reloaded['publishedPlane']);
        self::assertSame($research['result'], $reloaded['steps'][array_search('tool_succeeded', self::types($reloaded), true)]['result']);
    }

    public function testRejectingTheToolEndsTheMissionWithoutRunningIt(): void
    {
        $this->signIn();
        $waiting = $this->start();

        $rejected = $this->decideTool($waiting['uuid'], false);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $rejected['status']);
        self::assertNotContains('tool_succeeded', self::types($rejected));
        self::assertSame('rejected', self::types($rejected)[array_key_last($rejected['steps'])]);
        self::assertSame(1, $this->modelCalls);
    }

    public function testRejectingThePublicationCreatesNoPlane(): void
    {
        $this->signIn();
        $waiting = $this->start();
        $this->decideTool($waiting['uuid'], true);

        $rejected = $this->decidePublication($waiting['uuid'], false);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $rejected['status']);
        self::assertContains('proposal_created', self::types($rejected), 'What tells both rejections apart.');
        self::assertArrayNotHasKey('publishedPlane', $rejected);
        self::assertSame(0, $this->planeCount());
    }

    public function testAModelFailureIsAFailedMissionThatKeepsTheResearch(): void
    {
        $this->signIn();
        $this->publication = null;
        $waiting = $this->start();

        $failed = $this->decideTool($waiting['uuid'], true);

        self::assertResponseIsSuccessful();
        self::assertSame('failed', $failed['status']);
        self::assertContains('tool_succeeded', self::types($failed));
        self::assertSame('failed', $this->get($waiting['uuid'])['status']);
    }

    /** The page answers a 422 by reading the mission again: that read has to tell the truth. */
    public function testConflictsAreA422AndTheMissionCanBeReadAfterwards(): void
    {
        $this->signIn();
        $waiting = $this->start();

        $this->start();
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->decidePublication($waiting['uuid'], true);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->decideTool($waiting['uuid'], false);
        self::assertResponseIsSuccessful();
        $this->decideTool($waiting['uuid'], true);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame('rejected', $this->get($waiting['uuid'])['status']);
        self::assertSame(1, $this->modelCalls);
    }

    public function testAnonymousVisitorCanReadButNotStartOrDecide(): void
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_countries', [], ['messages' => []]);
        $this->em->persist($mission);
        $this->em->flush();
        $uuid = $mission->getUuid()->toRfc4122();

        self::assertSame('waiting_tool_approval', $this->get($uuid)['status']);
        self::assertResponseIsSuccessful();

        foreach ([
            fn () => $this->start(),
            fn () => $this->decideTool($uuid, true),
            fn () => $this->decidePublication($uuid, true),
        ] as $write) {
            $write();
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
        self::assertSame('waiting_tool_approval', $this->get($uuid)['status']);
        self::assertSame(0, $this->modelCalls);
    }

    public function testWritesWithoutTheBrowserProofAreRefused(): void
    {
        $this->signIn();

        $this->client->request('POST', '/api/agent_missions', server: [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->modelCalls);
    }
}
