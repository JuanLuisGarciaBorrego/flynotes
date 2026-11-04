<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AgentMission;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\PaperPlaneManager;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The documentation tells the truth: every response an operation documents is provoked against the real API, and
 * every response provoked has to be documented, except the usual errors that the general description explains.
 */
class OpenApiContractTest extends WebTestCase
{
    private const string UNKNOWN = '00000000-0000-4000-8000-000000000000';
    private const string MESSAGE = '{"message":"A message long enough to fly."}';
    private const string AI_VERSION = '{"text":"A MESSAGE LONG ENOUGH TO FLY!","tone":"Excited"}';

    private const array USUAL_ERRORS = [400, 401, 403, 404, 422];

    /**
     * The limit of the first is relaxed to 1000 in the test environment, so reaching it here would take too long. The
     * others need AI_ENABLED=0 from the boot of the kernel: AiEnabledToggleTest provokes them.
     */
    private const array NOT_PROVOKED = [
        'POST /api/participants 429',
        'POST /api/agent_missions 503',
        'POST /api/agent_missions/{uuid}/tool-decision 503',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private bool $modelIsDown = false;
    private int $missionCalls = 0;
    /** @var array<string, true> */
    private array $provoked = [];
    private string $clientIp;
    private Participant $me;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        // Rate limits outlive the test: a fresh address each run.
        $this->clientIp = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);

        static::getContainer()->set('ai.platform.generic.default', MockPlatformFactory::createPlatform(function ($model, $input, array $options): ResultInterface|string {
            if ($this->modelIsDown) {
                throw new \RuntimeException('The model is down.');
            }
            if (!isset($options['tools'])) {
                return self::AI_VERSION;
            }

            return 1 === (++$this->missionCalls % 2)
                ? new ToolCallResult([new ToolCall('call_1', 'get_sky_countries')])
                : new ToolCallResult([new ToolCall('call_2', 'publish_sky_report', ['title' => 'A title', 'message' => 'A report long enough to fly.'])]);
        }, eventDispatcher: static::getContainer()->get('event_dispatcher')));

        $this->me = $this->participants()->create(ParticipantType::Human, 'Me', 'ES');
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function participants(): ParticipantManager
    {
        return static::getContainer()->get(ParticipantManager::class);
    }

    /** @return array<string, string> */
    private function bearer(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$this->me->getCode()];
    }

    /** Finds the author again because deciding on a mission clears the entity manager. */
    private function plane(Participant $author): string
    {
        $author = $this->em->find(Participant::class, $author->getId());

        return static::getContainer()->get(PaperPlaneManager::class)->create($author, 'A message long enough to fly.')->getUuid()->toRfc4122();
    }

    private function waitingMission(): string
    {
        $mission = new AgentMission();
        $mission->requestToolApproval('call_1', 'get_sky_countries', [], ['messages' => []]);
        $this->em->persist($mission);
        $this->em->flush();

        return $mission->getUuid()->toRfc4122();
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function provoke(int $status, string $method, string $template, array $variables = [], ?string $body = null, array $server = []): array
    {
        $path = strtr($template, ['{uuid}' => $variables['uuid'] ?? '']);
        $server += ['HTTP_ACCEPT' => 'application/ld+json', 'REMOTE_ADDR' => $this->clientIp];
        if ('POST' === $method) {
            $server += ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(12))];
        }

        $this->client->request($method, $path.($variables['query'] ?? ''), server: $server, content: $body);
        $response = $this->client->getResponse();
        $name = \sprintf('%s %s %d', $method, $template, $status);

        self::assertSame($status, $response->getStatusCode(), $name.': '.$response->getContent());
        self::assertStringStartsWith($status >= 400 ? 'application/problem+json' : 'application/ld+json', (string) $response->headers->get('Content-Type'), $name);
        $this->provoked[$name] = true;

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** @return array<string, array<int, true>> */
    private function documented(): array
    {
        $this->client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json', 'REMOTE_ADDR' => $this->clientIp]);
        $documented = [];
        foreach (json_decode((string) $this->client->getResponse()->getContent(), true)['paths'] as $path => $methods) {
            foreach (array_intersect_key($methods, ['get' => 1, 'post' => 1]) as $method => $operation) {
                foreach (array_keys($operation['responses']) as $status) {
                    $documented[\sprintf('%s %s %d', strtoupper($method), $path, $status)] = true;
                }
            }
        }

        return $documented;
    }

    public function testEveryDocumentedResponseHappensAndNothingElseDoes(): void
    {
        $this->participantResponses();
        $this->planeResponses();
        $this->missionResponses();
        $this->everyProtectedOperationRefusesAnonymousAndCrossSiteRequests();

        $documented = array_keys($this->documented());
        $provoked = array_keys($this->provoked);

        self::assertSame([], array_values(array_diff($documented, $provoked, self::NOT_PROVOKED)), 'Documented, but it never happens.');
        self::assertSame([], array_values(array_filter(
            array_diff($provoked, $documented),
            static fn (string $name): bool => !\in_array((int) substr($name, -3), self::USUAL_ERRORS, true),
        )), 'It happens, but it is not documented.');
    }

    private function participantResponses(): void
    {
        $this->provoke(201, 'POST', '/api/participants', body: '{"name":"Ada","countryCode":"ES"}');
        $this->client->getCookieJar()->clear();
        $this->provoke(400, 'POST', '/api/participants', body: '{nope');
        $this->provoke(422, 'POST', '/api/participants', body: '{"name":"","countryCode":"xx"}');

        $this->provoke(200, 'POST', '/api/participants/recover', body: json_encode(['code' => $this->me->getCode()]));
        $this->client->getCookieJar()->clear();
        $this->provoke(400, 'POST', '/api/participants/recover', body: '{"code":"nope"}');
        $this->provoke(422, 'POST', '/api/participants/recover', body: '{"code":""}');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->provoke(400, 'POST', '/api/participants/recover', body: '{"code":"nope"}');
        }
        $this->provoke(429, 'POST', '/api/participants/recover', body: '{"code":"nope"}');
        self::assertResponseHasHeader('Retry-After');

        $this->provoke(200, 'GET', '/api/participants/me', server: $this->bearer());
    }

    private function planeResponses(): void
    {
        $other = $this->participants()->create(ParticipantType::Human, 'Other', 'FR');
        $theirs = $this->plane($other);

        $this->provoke(200, 'GET', '/api/paper_planes');
        $this->provoke(200, 'GET', '/api/paper_planes/{uuid}', ['uuid' => $theirs]);
        $this->provoke(404, 'GET', '/api/paper_planes/{uuid}', ['uuid' => self::UNKNOWN]);

        $launch = '/api/paper_planes';
        $mine = $this->provoke(201, 'POST', $launch, body: self::MESSAGE, server: $this->bearer());
        self::assertSame('pending', $mine['aiStatus']);
        $this->provoke(400, 'POST', $launch, body: '{nope', server: $this->bearer());
        $this->provoke(422, 'POST', $launch, body: '{"message":"short"}', server: $this->bearer());

        $this->modelIsDown = true;
        self::assertArrayNotHasKey('aiStatus', $this->provoke(201, 'POST', $launch, body: self::MESSAGE, server: $this->bearer()), 'Launched anyway, without a proposal.');
        $retry = '/api/paper_planes/{uuid}/ai-message/retry';
        $this->provoke(503, 'POST', $retry, ['uuid' => $mine['uuid']], server: $this->bearer());
        $this->modelIsDown = false;

        $retried = $this->provoke(200, 'POST', $retry, ['uuid' => $mine['uuid']], server: $this->bearer());
        $this->provoke(403, 'POST', $retry, ['uuid' => $theirs], server: $this->bearer());
        $this->provoke(404, 'POST', $retry, ['uuid' => self::UNKNOWN], server: $this->bearer());

        $decision = '/api/paper_planes/{uuid}/ai-message/decision';
        $this->provoke(400, 'POST', $decision, ['uuid' => $mine['uuid']], '{nope', $this->bearer());
        $this->provoke(422, 'POST', $decision, ['uuid' => $mine['uuid']], '{"accepted":true}', $this->bearer());
        $this->provoke(403, 'POST', $decision, ['uuid' => $theirs], '{"accepted":false}', $this->bearer());
        $this->provoke(404, 'POST', $decision, ['uuid' => self::UNKNOWN], '{"accepted":false}', $this->bearer());
        $this->provoke(409, 'POST', $decision, ['uuid' => $mine['uuid']], json_encode(['accepted' => true, 'versionId' => self::UNKNOWN]), $this->bearer());
        $this->provoke(200, 'POST', $decision, ['uuid' => $mine['uuid']], json_encode(['accepted' => true, 'versionId' => $retried['aiVersionId']]), $this->bearer());
        $this->provoke(409, 'POST', $retry, ['uuid' => $mine['uuid']], server: $this->bearer());

        $stamps = '/api/paper_planes/{uuid}/stamps';
        $this->provoke(403, 'POST', $stamps, ['uuid' => $mine['uuid']], server: $this->bearer());
        $this->provoke(404, 'POST', $stamps, ['uuid' => self::UNKNOWN], server: $this->bearer());
        $this->provoke(201, 'POST', $stamps, ['uuid' => $theirs], server: $this->bearer());
        $this->provoke(409, 'POST', $stamps, ['uuid' => $theirs], server: $this->bearer());

        $this->provoke(200, 'GET', '/api/me/paper_planes', server: $this->bearer());
        $this->provoke(400, 'GET', '/api/me/paper_planes', ['query' => '?page=0'], server: $this->bearer());
        $this->provoke(200, 'GET', '/api/me/paper_planes/stats', server: $this->bearer());
    }

    private function missionResponses(): void
    {
        $start = '/api/agent_missions';
        $toolDecision = '/api/agent_missions/{uuid}/tool-decision';
        $decision = '/api/agent_missions/{uuid}/decision';
        $read = '/api/agent_missions/{uuid}';

        $this->provoke(404, 'GET', '/api/agent_missions/current');
        $this->provoke(404, 'GET', $read, ['uuid' => self::UNKNOWN]);
        $this->provoke(400, 'POST', $start, body: '', server: $this->bearer());

        $mission = $this->provoke(201, 'POST', $start, body: '{}', server: $this->bearer());
        self::assertSame('waiting_tool_approval', $mission['status']);
        $uuid = $mission['uuid'];

        $this->provoke(200, 'GET', $read, ['uuid' => $uuid]);
        $this->provoke(200, 'GET', '/api/agent_missions/current');
        $this->provoke(422, 'POST', $start, body: '{}', server: $this->bearer());

        foreach ([$toolDecision, $decision] as $path) {
            $this->provoke(400, 'POST', $path, ['uuid' => $uuid], '{"accepted":"yes"}', $this->bearer());
            $this->provoke(404, 'POST', $path, ['uuid' => self::UNKNOWN], '{"accepted":false}', $this->bearer());
        }
        $this->provoke(422, 'POST', $toolDecision, ['uuid' => $uuid], '{}', $this->bearer());
        $this->provoke(422, 'POST', $decision, ['uuid' => $uuid], '{"accepted":true}', $this->bearer());

        self::assertSame('waiting_approval', $this->provoke(200, 'POST', $toolDecision, ['uuid' => $uuid], '{"accepted":true}', $this->bearer())['status']);
        self::assertSame('completed', $this->provoke(200, 'POST', $decision, ['uuid' => $uuid], '{"accepted":true}', $this->bearer())['status']);
    }

    private function everyProtectedOperationRefusesAnonymousAndCrossSiteRequests(): void
    {
        $uuid = $this->plane($this->me);
        $mission = $this->waitingMission();
        $operations = [
            ['GET', '/api/participants/me', ''],
            ['GET', '/api/me/paper_planes', ''],
            ['GET', '/api/me/paper_planes/stats', ''],
            ['POST', '/api/paper_planes', ''],
            ['POST', '/api/paper_planes/{uuid}/ai-message/retry', $uuid],
            ['POST', '/api/paper_planes/{uuid}/ai-message/decision', $uuid],
            ['POST', '/api/paper_planes/{uuid}/stamps', $uuid],
            ['POST', '/api/agent_missions', ''],
            ['POST', '/api/agent_missions/{uuid}/tool-decision', $mission],
            ['POST', '/api/agent_missions/{uuid}/decision', $mission],
        ];

        foreach ($operations as [$method, $template, $identifier]) {
            $this->provoke(401, $method, $template, ['uuid' => $identifier], 'POST' === $method ? '{}' : null);
        }

        $writes = array_filter($operations, static fn (array $operation): bool => 'POST' === $operation[0]);
        foreach ([...$writes, ['POST', '/api/participants', ''], ['POST', '/api/participants/recover', '']] as [$method, $template, $identifier]) {
            $this->provoke(403, $method, $template, ['uuid' => $identifier], '{}', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_CSRF_TOKEN' => '']);
        }
    }

    /** What the general description promises, for every endpoint. */
    public function testAnInvalidBearerGetsAnEmpty401EvenOnAPublicEndpoint(): void
    {
        $this->client->request('GET', '/api/paper_planes', server: ['HTTP_AUTHORIZATION' => 'Bearer nope', 'REMOTE_ADDR' => $this->clientIp]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testBearerFloodGetsAnEmpty429WithRetryAfter(): void
    {
        for ($attempt = 0; $attempt < 11; ++$attempt) {
            $this->client->request('GET', '/api/paper_planes', server: ['HTTP_AUTHORIZATION' => 'Bearer nope', 'REMOTE_ADDR' => $this->clientIp]);
        }

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testBearerWritesAreExemptFromCsrf(): void
    {
        $this->client->request('POST', '/api/paper_planes', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site', 'REMOTE_ADDR' => $this->clientIp, ...$this->bearer()], content: self::MESSAGE);

        self::assertResponseStatusCodeSame(201);
    }

    public function testOtherFormatsAreRefused(): void
    {
        $this->client->request('POST', '/api/participants', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_CSRF_TOKEN' => bin2hex(random_bytes(12)), 'REMOTE_ADDR' => $this->clientIp], content: 'x');
        self::assertResponseStatusCodeSame(415);

        $this->client->request('GET', '/api/paper_planes', server: ['HTTP_ACCEPT' => 'text/csv', 'REMOTE_ADDR' => $this->clientIp]);
        self::assertResponseStatusCodeSame(406);
    }

    /** The JSON format the description promises answers errors the same way. */
    public function testErrorsAreProblemsForJsonClientsToo(): void
    {
        $this->client->request('GET', '/api/paper_planes/'.self::UNKNOWN, server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->clientIp]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }
}
