<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\PaperPlane;
use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\EventListener\ApiCsrfListener;
use App\EventListener\ParticipantBearerListener;
use App\Manager\ParticipantManager;
use App\Security\BearerAwareSessionStrategy;
use App\Security\McpTokenHandler;
use App\Security\ParticipantApiTokenHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Bearer authentication for humans on the `main` firewall, and the CSRF policy that depends on it: only a
 * Bearer that actually authenticated is exempt, it prevails over the session, and it never touches it.
 */
class ParticipantApiBearerTest extends WebTestCase
{
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
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
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

    private function participant(ParticipantType $type, string $name = 'Test'): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, $name, 'ES');
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $uri, ?string $authorization = null, ?array $body = null, bool $csrf = false): array
    {
        $server = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        if ($csrf) {
            $server['HTTP_CSRF_TOKEN'] = bin2hex(random_bytes(16));
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Registers a human through the API, as the browser does: the client keeps the session cookie. */
    private function startSession(string $name): string
    {
        $data = $this->api('POST', '/api/participants', body: ['name' => $name, 'countryCode' => 'ES'], csrf: true);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNotNull($this->sessionId());

        return $data['code'];
    }

    private function sessionId(): ?string
    {
        return $this->client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();
    }

    private function me(?string $authorization = null): ?string
    {
        return $this->api('GET', '/api/participants/me', $authorization)['name'] ?? null;
    }

    private function planeCount(): int
    {
        return $this->em->getRepository(PaperPlane::class)->count();
    }

    private function assertGenericUnauthorized(?string $secret = null): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('WWW-Authenticate', self::GENERIC_CHALLENGE);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        if (null !== $secret) {
            self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->headers);
        }
    }

    public function testHumanBearerAccessesProtectedOperation(): void
    {
        $human = $this->participant(ParticipantType::Human, 'Bearer Human');

        $this->em->clear();

        self::assertSame('Bearer Human', $this->me('Bearer '.$human->getCode()));
        self::assertResponseIsSuccessful();

        $this->api('GET', '/api/me/paper_planes', 'Bearer '.$human->getCode());
        self::assertResponseIsSuccessful();
    }

    public function testCodeIsNormalized(): void
    {
        $human = $this->participant(ParticipantType::Human, 'Bearer Human');

        self::assertSame('Bearer Human', $this->me('Bearer '.strtolower(str_replace('-', '', $human->getCode()))));
    }

    public function testHumanBearerCreatesPlaneWithoutCsrf(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $before = $this->planeCount();

        $this->api('POST', '/api/paper_planes', 'Bearer '.$human->getCode(), ['message' => 'Hola desde Postman']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($before + 1, $this->planeCount());
    }

    public function testAnonymousAccessToProtectedOperationIsStillUnauthorized(): void
    {
        $this->api('GET', '/api/participants/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInvalidBearerIsUnauthorizedEverywhere(): void
    {
        $requests = [
            ['GET', '/api/participants/me', null],
            // Public operations too: the lazy firewall must not skip a credential nobody reads.
            ['GET', '/api/paper_planes', null],
            ['GET', '/', null],
            ['POST', '/api/paper_planes', ['message' => 'Hola desde FlyNotes']],
        ];
        foreach ($requests as [$method, $uri, $body]) {
            $this->api($method, $uri, 'Bearer '.self::UNKNOWN_CODE, $body);
            $this->assertGenericUnauthorized(self::UNKNOWN_CODE);
        }
    }

    /** Malformed, unknown and bot codes must be indistinguishable. */
    public function testFailureDoesNotRevealWhatTheCodeWas(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        foreach ([$bot->getCode(), self::UNKNOWN_CODE, 'garbage', 'a b'] as $code) {
            $this->api('GET', '/api/participants/me', 'Bearer '.$code);
            $this->assertGenericUnauthorized($code);
        }
    }

    public function testBotCodeDoesNotAuthenticateOnTheApi(): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $before = $this->planeCount();

        $this->api('POST', '/api/paper_planes', 'Bearer '.$bot->getCode(), ['message' => 'Hola desde un bot']);

        $this->assertGenericUnauthorized($bot->getCode());
        self::assertSame($before, $this->planeCount());
    }

    public function testHumanCodeDoesNotAuthenticateOnMcp(): void
    {
        $human = $this->participant(ParticipantType::Human);

        $this->api('POST', '/mcp', 'Bearer '.$human->getCode(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $this->assertGenericUnauthorized($human->getCode());
    }

    public function testBotCodeStillAuthenticatesOnMcp(): void
    {
        $bot = $this->participant(ParticipantType::Bot);

        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bot->getCode(),
        ], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testWebSessionKeepsWorking(): void
    {
        $this->startSession('Session Human');

        self::assertSame('Session Human', $this->me());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/my-planes');
        self::assertResponseIsSuccessful();

        $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola desde la web'], csrf: true);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testSessionWriteStillRequiresCsrf(): void
    {
        $this->startSession('Session Human');
        $before = $this->planeCount();

        $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola sin CSRF']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($before, $this->planeCount());
    }

    public function testInvalidBearerWithValidSessionIsRejectedAndLeavesTheSessionAlone(): void
    {
        $this->startSession('Session Human');
        $sessionId = $this->sessionId();
        $before = $this->planeCount();

        // Malformed values included: Symfony's extractor does not even see them as a token.
        foreach (['Bearer '.self::UNKNOWN_CODE, 'Bearer garbage', 'Bearer a b', 'Bearer', 'bearer '.self::UNKNOWN_CODE] as $authorization) {
            // Its own "origin": this test is about the rejection, not about the limit of failed attempts.
            $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));

            // With and without CSRF proof: it is the authentication that fails, whichever the request carries.
            foreach ([true, false] as $csrf) {
                $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde FlyNotes'], $csrf);
                $this->assertGenericUnauthorized();
            }

            $this->api('GET', '/api/participants/me', $authorization);
            $this->assertGenericUnauthorized();
        }

        self::assertSame($before, $this->planeCount());
        self::assertSame($sessionId, $this->sessionId());
        self::assertSame('Session Human', $this->me());
    }

    public function testBotCodeWithValidSessionIsRejected(): void
    {
        $this->startSession('Session Human');
        $bot = $this->participant(ParticipantType::Bot);

        $this->api('GET', '/api/participants/me', 'Bearer '.$bot->getCode());

        $this->assertGenericUnauthorized($bot->getCode());
    }

    public function testValidBearerDoesNotStartASession(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $authorization = 'Bearer '.$human->getCode();

        $this->api('GET', '/api/participants/me', $authorization);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde Postman']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        self::assertNull($this->sessionId());
        $this->api('GET', '/api/participants/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** Registration and recovery log in through the session: with a Bearer, that must not stick either. */
    public function testProgrammaticLoginDuringBearerRequestDoesNotStartASession(): void
    {
        $human = $this->participant(ParticipantType::Human);
        $authorization = 'Bearer '.$human->getCode();

        $this->api('POST', '/api/participants/recover', $authorization, ['code' => $human->getCode()]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->api('POST', '/api/participants', $authorization, ['name' => 'Otra', 'countryCode' => 'ES']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testBearerPrevailsOverSessionWithoutReplacingIt(): void
    {
        $this->startSession('Session Human');
        $sessionId = $this->sessionId();
        $human = $this->participant(ParticipantType::Human, 'Bearer Human');
        $authorization = 'Bearer '.$human->getCode();

        self::assertSame('Bearer Human', $this->me($authorization));
        self::assertSame($sessionId, $this->sessionId(), 'The session id is not rotated.');

        $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde Postman']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1, $this->api('GET', '/api/me/paper_planes', $authorization)['totalItems']);
        self::assertSame($sessionId, $this->sessionId(), 'The session id is not rotated.');

        // Back to the cookie alone: the session keeps its own identity, and nothing of the Bearer's.
        self::assertSame('Session Human', $this->me());
        self::assertSame(0, $this->api('GET', '/api/me/paper_planes')['totalItems']);
    }

    public function testBearerOfTheSessionOwnerDoesNotExemptTheNextSessionWrite(): void
    {
        $code = $this->startSession('Session Human');

        $this->api('POST', '/api/paper_planes', 'Bearer '.$code, ['message' => 'Hola desde Postman']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola sin CSRF']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnonymousRequestWithFakeAuthorizationCannotSkipCsrf(): void
    {
        $before = $this->planeCount();
        $body = ['name' => 'Otra', 'countryCode' => 'ES'];

        // Other schemes are not a Bearer attempt: nothing authenticated, so CSRF still applies.
        foreach (['Basic dXNlcjpwYXNz', 'Token '.self::UNKNOWN_CODE, 'Bearer'.self::UNKNOWN_CODE] as $authorization) {
            $this->api('POST', '/api/participants', $authorization, $body);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $authorization);
        }

        // A fake Bearer does not reach CSRF at all: it is refused first.
        foreach (['Bearer '.self::UNKNOWN_CODE, 'Bearer garbage', 'Bearer a b', 'Bearer'] as $authorization) {
            $this->api('POST', '/api/participants', $authorization, $body);
            $this->assertGenericUnauthorized();
        }

        self::assertSame($before, $this->planeCount());
    }

    public function testPublicOperationsKeepWorkingAnonymously(): void
    {
        $this->api('GET', '/api/paper_planes');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->api('POST', '/api/participants', body: ['name' => 'Otra', 'countryCode' => 'ES'], csrf: true);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testRequestRefusedByCsrfDoesNotConsumeRegistrationBudget(): void
    {
        $limiter = static::getContainer()->get('limiter.participant_registration')->create($this->client->getServerParameter('REMOTE_ADDR'));
        $before = $limiter->consume(0)->getRemainingTokens();

        $this->api('POST', '/api/participants', body: ['name' => 'Otra', 'countryCode' => 'ES']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($before, $limiter->consume(0)->getRemainingTokens());
    }

    /** What the session holds, read from the mock storage: the identity of A, byte by byte. */
    private function sessionAttributes(): array
    {
        $path = static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$this->sessionId().'.mocksess';
        $attributes = unserialize((string) file_get_contents($path))['_sf2_attributes'];
        self::assertArrayHasKey('_security_main', $attributes);

        return $attributes;
    }

    public function testBearerOfAnotherHumanLeavesTheSessionByteIdentical(): void
    {
        $this->startSession('Human A');
        $sessionId = $this->sessionId();
        $session = $this->sessionAttributes();
        $authorization = 'Bearer '.$this->participant(ParticipantType::Human, 'Human B')->getCode();

        self::assertSame('Human B', $this->me($authorization));
        $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde Postman']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame($sessionId, $this->sessionId());
        self::assertSame($session, $this->sessionAttributes());
        self::assertSame('Human A', $this->me());
    }

    public function testInvalidBearerLeavesTheSessionByteIdentical(): void
    {
        $this->startSession('Human A');
        $sessionId = $this->sessionId();
        $session = $this->sessionAttributes();

        foreach (['Bearer '.self::UNKNOWN_CODE, 'Bearer a b'] as $authorization) {
            $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde FlyNotes'], true);
            $this->assertGenericUnauthorized();
        }

        self::assertSame($sessionId, $this->sessionId());
        self::assertSame($session, $this->sessionAttributes());
        self::assertSame('Human A', $this->me());
    }

    /** Recovery logs in programmatically: under a Bearer, neither the identity nor the session id may change. */
    public function testRecoveryDuringBearerRequestLeavesTheSessionAlone(): void
    {
        $this->startSession('Human A');
        $sessionId = $this->sessionId();
        $session = $this->sessionAttributes();
        $other = $this->participant(ParticipantType::Human, 'Human B');

        $this->api('POST', '/api/participants/recover', 'Bearer '.$other->getCode(), ['code' => $other->getCode()]);

        self::assertResponseIsSuccessful();
        self::assertSame($sessionId, $this->sessionId());
        self::assertSame($session, $this->sessionAttributes());
        self::assertSame('Human A', $this->me());
    }

    /** Session fixation protection is only skipped for Bearer requests: a real login still rotates the id. */
    public function testRecoveryInTheBrowserStillSwitchesIdentityAndRotatesTheSession(): void
    {
        $this->startSession('Human A');
        $sessionId = $this->sessionId();
        $other = $this->participant(ParticipantType::Human, 'Human B');

        $this->api('POST', '/api/participants/recover', body: ['code' => $other->getCode()], csrf: true);

        self::assertResponseIsSuccessful();
        self::assertNotSame($sessionId, $this->sessionId());
        self::assertSame('Human B', $this->me());
    }

    public function testRegistrationBudgetIsOnlyConsumedByAcceptedRequests(): void
    {
        $limiter = static::getContainer()->get('limiter.participant_registration')->create($this->client->getServerParameter('REMOTE_ADDR'));
        $human = $this->participant(ParticipantType::Human);
        $body = ['name' => 'Otra', 'countryCode' => 'ES'];
        $before = $limiter->consume(0)->getRemainingTokens();

        $this->api('POST', '/api/participants', 'Bearer '.self::UNKNOWN_CODE, $body);
        $this->assertGenericUnauthorized();
        $this->api('POST', '/api/participants', 'Bearer a b', $body);
        $this->assertGenericUnauthorized();
        self::assertSame($before, $limiter->consume(0)->getRemainingTokens());

        $this->api('POST', '/api/participants', 'Bearer '.$human->getCode(), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($before - 1, $limiter->consume(0)->getRemainingTokens());
    }

    /** The client reuses one kernel across requests, as a FrankenPHP worker does: no identity may carry over. */
    public function testIdentityDoesNotCarryOverBetweenRequestsOfTheSameWorker(): void
    {
        $this->startSession('Human A');
        $cookie = $this->client->getCookieJar()->get(self::SESSION_COOKIE);
        $authorization = 'Bearer '.$this->participant(ParticipantType::Human, 'Human B')->getCode();

        self::assertSame('Human B', $this->me($authorization));
        self::assertSame('Human A', $this->me());
        self::assertSame('Human B', $this->me($authorization));

        $this->client->getCookieJar()->clear();
        self::assertNull($this->me());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('Human B', $this->me($authorization));
        self::assertNull($this->me());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // A CSRF exemption from an earlier request must not survive either.
        $this->client->getCookieJar()->set($cookie);
        $this->api('POST', '/api/paper_planes', $authorization, ['message' => 'Hola desde Postman']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->api('POST', '/api/paper_planes', body: ['message' => 'Hola sin CSRF']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @param class-string $class */
    #[DataProvider('longLivedServices')]
    public function testLongLivedServicesCannotKeepRequestState(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isReadOnly(), $class);
        foreach ($reflection->getProperties() as $property) {
            $type = (string) $property->getType();
            foreach ([Request::class, TokenInterface::class, Participant::class] as $requestState) {
                self::assertFalse(is_a($type, $requestState, true), $class.'::$'.$property->getName());
            }
        }
    }

    /** @return iterable<array{class-string}> */
    public static function longLivedServices(): iterable
    {
        yield [ParticipantBearerListener::class];
        yield [ApiCsrfListener::class];
        yield [BearerAwareSessionStrategy::class];
        yield [ParticipantApiTokenHandler::class];
        yield [McpTokenHandler::class];
    }

    /** @return array<string, mixed> */
    private function mcp(string $method, array $params, ?string $authorization, array $extraServer = []): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => $method,
            ...$extraServer,
        ];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            \JSON_THROW_ON_ERROR,
        ));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testBotBearerStillReachesTheMcpTools(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'Bot Test');
        $authorization = 'Bearer '.$bot->getCode();

        $tools = array_column($this->mcp('tools/list', [], $authorization)['result']['tools'], 'name');
        sort($tools);
        self::assertSame([
            'decide_paper_plane_ai',
            'get_my_paper_plane_stats',
            'get_paper_plane',
            'get_profile',
            'launch_paper_plane',
            'list_my_paper_planes',
            'list_paper_planes',
            'register_agent',
            'retry_paper_plane_ai',
            'stamp_paper_plane',
        ], $tools);

        $profile = $this->mcp('tools/call', ['name' => 'get_profile', 'arguments' => new \stdClass()], $authorization, ['HTTP_MCP_NAME' => 'get_profile']);
        self::assertResponseIsSuccessful();
        self::assertFalse($profile['result']['isError'] ?? false);
        self::assertStringContainsString('Bot Test', json_encode($profile['result'], \JSON_THROW_ON_ERROR));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testHumanBearerNeverReachesMcpToolsEvenWithASession(): void
    {
        $code = $this->startSession('Session Human');

        $this->mcp('tools/call', ['name' => 'get_profile', 'arguments' => new \stdClass()], 'Bearer '.$code, ['HTTP_MCP_NAME' => 'get_profile']);

        $this->assertGenericUnauthorized($code);
    }
}
