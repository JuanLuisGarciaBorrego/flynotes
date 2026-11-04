<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Failed Bearer attempts per IP, with one budget shared by the API (`main`) and MCP (`mcp`) firewalls. */
class BearerRateLimitTest extends WebTestCase
{
    /** The whole catalogue, named in ToolSchemaCompatibilityTest: being listed needs no credential. */
    private const int MCP_TOOLS = 10;

    private const int LIMIT = 10;
    private const string SESSION_COOKIE = 'MOCKSESSID'; // mock_file storage in the test env
    private const string UNKNOWN_CODE = 'AAAA-AAAA-AAAA-AAAA';
    private const string GENERIC_CHALLENGE = 'Bearer error="invalid_token",error_description="Invalid credentials."';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // A distinct "origin" per test so the persisted rate limiter state never leaks between tests/runs.
        $this->useNewIp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    private function useNewIp(): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
    }

    private function remaining(): int
    {
        return static::getContainer()->get('limiter.bearer_authentication_failure')
            ->create($this->client->getServerParameter('REMOTE_ADDR'))
            ->consume(0)
            ->getRemainingTokens();
    }

    private function participant(ParticipantType $type, string $name = 'Test'): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, $name, 'ES');
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $uri, ?string $authorization = null, ?array $body = null, bool $csrf = false, array $extra = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json', ...$extra];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        if ($csrf) {
            $server['HTTP_CSRF_TOKEN'] = bin2hex(random_bytes(16));
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function mcp(?string $authorization = null, string $method = 'tools/list'): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => $method,
        ];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ],
        ]], \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function failOnApi(int $times = 1, string $authorization = 'Bearer '.self::UNKNOWN_CODE): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->api('GET', '/api/participants/me', $authorization);
            $this->assertUnauthorized();
        }
    }

    private function exhaustBudget(): void
    {
        $this->failOnApi(self::LIMIT);
        self::assertSame(0, $this->remaining());
    }

    private function assertUnauthorized(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('WWW-Authenticate', self::GENERIC_CHALLENGE);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    private function assertTooManyRequests(?string $secret = null): void
    {
        $response = $this->client->getResponse();
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        // Sliding window: the rest of the current minute plus the fade-out of its hits.
        self::assertLessThanOrEqual(120, (int) $response->headers->get('Retry-After'));
        self::assertSame('', (string) $response->getContent());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        if (null !== $secret) {
            self::assertStringNotContainsString($secret, (string) $response->headers);
        }
    }

    private function startSession(string $name): void
    {
        $this->api('POST', '/api/participants', body: ['name' => $name, 'countryCode' => 'ES'], csrf: true);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /** What the session holds, read from the mock storage. */
    private function sessionAttributes(): array
    {
        $id = $this->client->getCookieJar()->get(self::SESSION_COOKIE)->getValue();
        $path = static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$id.'.mocksess';

        return unserialize((string) file_get_contents($path))['_sf2_attributes'];
    }

    public function testValidHumanBearerOnTheApiCostsNothing(): void
    {
        $human = $this->participant(ParticipantType::Human, 'Human Test');

        self::assertSame('Human Test', $this->api('GET', '/api/participants/me', 'Bearer '.$human->getCode())['name']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testValidBotBearerOnMcpCostsNothing(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        self::assertCount(self::MCP_TOOLS, $this->mcp('Bearer '.$bot->getCode())['result']['tools']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testEachFailedAttemptIsCountedOnceWithTheSameResponseOnBothChannels(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $bot = $this->participant(ParticipantType::Bot);

        $attempts = [
            // Unknown and malformed codes on each channel.
            fn () => $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE),
            fn () => $this->api('GET', '/api/participants/me', 'Bearer garbage'),
            fn () => $this->mcp('Bearer '.self::UNKNOWN_CODE),
            fn () => $this->mcp('Bearer garbage'),
            // Real codes of the wrong type: same 401, nothing tells them apart.
            fn () => $this->api('GET', '/api/participants/me', 'Bearer '.$bot->getCode()),
            fn () => $this->mcp('Bearer '.$human->getCode()),
        ];
        foreach ($attempts as $i => $attempt) {
            $attempt();
            $this->assertUnauthorized();
            self::assertSame(self::LIMIT - $i - 1, $this->remaining());
        }
    }

    public function testTheAttemptAfterTenFailuresIsTooManyRequests(): void
    {
        $this->exhaustBudget();

        $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE);

        $this->assertTooManyRequests(self::UNKNOWN_CODE);
    }

    public function testFailuresAreSharedBetweenApiAndMcp(): void
    {
        for ($i = 0; $i < self::LIMIT / 2; ++$i) {
            $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE);
            $this->assertUnauthorized();
            $this->mcp('Bearer '.self::UNKNOWN_CODE);
            $this->assertUnauthorized();
        }

        $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE);
        $this->assertTooManyRequests();
        $this->mcp('Bearer '.self::UNKNOWN_CODE);
        $this->assertTooManyRequests();
    }

    /** A blocked client cannot tell a real code from a wrong one, nor a human's from a bot's. */
    public function testWhenBlockedAValidCodeIsRefusedLikeAnyOther(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $bot = $this->participant(ParticipantType::Bot);
        $this->exhaustBudget();

        $this->api('GET', '/api/participants/me', 'Bearer '.$human->getCode());
        $this->assertTooManyRequests($human->getCode());
        $this->mcp('Bearer '.$bot->getCode());
        $this->assertTooManyRequests($bot->getCode());
    }

    public function testWhenBlockedNoQueryReachesTheDatabase(): void
    {
        $container = static::getContainer();
        if (!$container->getParameter('kernel.debug')) {
            self::markTestSkipped('Query profiling is only available with kernel.debug.');
        }
        $human = $this->participant(ParticipantType::Human);

        // Control: below the limit, the code is looked up.
        $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE);
        self::assertNotEmpty($container->get('doctrine.debug_data_holder')->getData()['default'] ?? []);

        $this->failOnApi(self::LIMIT - 1);
        foreach ([fn () => $this->api('GET', '/api/participants/me', 'Bearer '.$human->getCode()), fn () => $this->mcp('Bearer '.self::UNKNOWN_CODE)] as $blocked) {
            $blocked();
            $this->assertTooManyRequests();
            self::assertSame([], $container->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
        }
    }

    public function testSuccessNeitherConsumesNorResetsTheFailures(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $bot = $this->participant(ParticipantType::Bot);
        $this->failOnApi(self::LIMIT - 1);

        $this->api('GET', '/api/participants/me', 'Bearer '.$human->getCode());
        self::assertResponseIsSuccessful();
        $this->mcp('Bearer '.$bot->getCode());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->remaining());

        $this->failOnApi();
        $this->api('GET', '/api/participants/me', 'Bearer '.$human->getCode());
        $this->assertTooManyRequests();
    }

    public function testRequestsWithoutBearerDoNotConsumeAndAreNotBlocked(): void
    {
        $requests = static function (self $test): void {
            $test->api('GET', '/api/paper_planes');
            self::assertResponseIsSuccessful();
            $test->api('GET', '/api/participants/me');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            $test->api('GET', '/api/participants/me', 'Basic dXNlcjpwYXNz');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
            self::assertCount(self::MCP_TOOLS, $test->mcp()['result']['tools']);
        };

        $requests($this);
        self::assertSame(self::LIMIT, $this->remaining());

        $this->exhaustBudget();
        $requests($this);
    }

    public function testSessionRequestsDoNotConsumeAndAreNotBlocked(): void
    {
        $this->startSession('Session Human');
        $requests = function (): void {
            self::assertSame('Session Human', $this->api('GET', '/api/participants/me')['name']);
            $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola desde la web'], csrf: true);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        };

        $requests();
        self::assertSame(self::LIMIT, $this->remaining());

        $this->exhaustBudget();
        $requests();
    }

    public function testCsrfErrorsDoNotConsume(): void
    {
        $this->startSession('Session Human');

        $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola sin CSRF']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->api('POST', '/api/paper_planes', 'Basic dXNlcjpwYXNz', ['message' => 'Hola sin CSRF']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testApiValidationErrorsDoNotConsume(): void
    {
        $human = $this->participant(ParticipantType::Human);

        $this->api('POST', '/api/paper_planes', 'Bearer '.$human->getCode(), ['message' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testAuthorizationErrorsDoNotConsume(): void
    {
        $author = $this->participant(ParticipantType::Human, 'Author');
        $other = $this->participant(ParticipantType::Human, 'Other');
        $plane = $this->api('POST', '/api/paper_planes', 'Bearer '.$author->getCode(), ['message' => 'Hola desde FlyNotes']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->api('POST', '/api/paper_planes/'.$plane['uuid'].'/ai-message/retry', 'Bearer '.$other->getCode());

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testMalformedBearerCountsOnTheApiAndCannotDodgeTheBlock(): void
    {
        foreach (['Bearer a b', 'Bearer', 'bearer '.self::UNKNOWN_CODE] as $i => $authorization) {
            $this->failOnApi(authorization: $authorization);
            self::assertSame(self::LIMIT - $i - 1, $this->remaining());
        }
        $this->failOnApi(self::LIMIT - 3);

        $variants = ['Bearer '.self::UNKNOWN_CODE, 'Bearer a b', 'Bearer', 'bearer '.self::UNKNOWN_CODE, 'BEARER '.self::UNKNOWN_CODE, "Bearer\t".self::UNKNOWN_CODE, 'Bearer '.strtolower(self::UNKNOWN_CODE)];
        foreach ($variants as $authorization) {
            $this->api('GET', '/api/paper_planes', $authorization);
            $this->assertTooManyRequests();
            $this->mcp($authorization);
            $this->assertTooManyRequests();
        }
    }

    /** On MCP it is not an attempt: without a credential the request is anonymous, for the public tools. */
    public function testMalformedBearerOnMcpStaysAnonymousWithoutConsuming(): void
    {
        self::assertCount(self::MCP_TOOLS, $this->mcp('Bearer a b')['result']['tools']);

        self::assertSame(self::LIMIT, $this->remaining());
    }

    public function testBudgetIsPerIpAndDoesNotLeakToOtherClients(): void
    {
        $this->exhaustBudget();

        $this->useNewIp();
        self::assertSame(self::LIMIT, $this->remaining());
        $this->failOnApi();
    }

    public function testSpoofedForwardedHeadersDoNotDodgeTheLimit(): void
    {
        for ($i = 0; $i < self::LIMIT; ++$i) {
            $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE, extra: ['HTTP_X_FORWARDED_FOR' => '203.0.113.'.($i + 1), 'HTTP_FORWARDED' => 'for=203.0.113.'.($i + 1)]);
            $this->assertUnauthorized();
        }

        $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE, extra: ['HTTP_X_FORWARDED_FOR' => '203.0.113.200']);
        $this->assertTooManyRequests();
    }

    public function testInvalidBearerWithSessionIsRefusedWithoutTouchingTheSessionUntilAndAfterTheBlock(): void
    {
        $this->startSession('Session Human');
        $sessionId = $this->client->getCookieJar()->get(self::SESSION_COOKIE)->getValue();
        $session = $this->sessionAttributes();

        $this->failOnApi(self::LIMIT);
        $this->api('GET', '/api/participants/me', 'Bearer '.self::UNKNOWN_CODE);
        $this->assertTooManyRequests();

        self::assertSame($sessionId, $this->client->getCookieJar()->get(self::SESSION_COOKIE)->getValue());
        self::assertSame($session, $this->sessionAttributes());
        self::assertSame('Session Human', $this->api('GET', '/api/participants/me')['name']);
    }
}
