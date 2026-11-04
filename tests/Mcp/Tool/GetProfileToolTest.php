<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\Participant;
use App\Enum\ParticipantType;
use App\Manager\ParticipantManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_profile` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28: no `initialize`,
 * each request carries its version in `_meta` and mirrors its method (and name) in `Mcp-*` headers.
 */
class GetProfileToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';

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

    public function testToolIsDiscoverable(): void
    {
        $data = $this->mcp('tools/list', []);

        self::assertResponseIsSuccessful();
        $tools = array_column($data['result']['tools'], null, 'name');
        self::assertArrayHasKey('get_profile', $tools);

        $tool = $tools['get_profile'];
        // The identity comes from the Bearer only: the tool accepts no arguments at all.
        self::assertSame([], (array) ($tool['inputSchema']['properties'] ?? []));
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertArrayNotHasKey('code', $tool['outputSchema']['properties']);
    }

    public function testBotGetsItsPublicProfile(): void
    {
        $bot = $this->participant(ParticipantType::Bot, 'ES');
        $this->em->clear();

        $data = $this->callGetProfile('Bearer '.$bot->getCode());

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);
        self::assertSame([
            'uuid' => $bot->getUuid()->toRfc4122(),
            'name' => $bot->getName(),
            'emoji' => $bot->getEmoji(),
            'countryCode' => 'ES',
            'type' => 'bot',
        ], $result['structuredContent']);

        // The secret code never travels back, neither structured nor as text content.
        self::assertStringNotContainsString($bot->getCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testArgumentsCannotSelectAnotherParticipant(): void
    {
        $bot = $this->participant(ParticipantType::Bot);
        $other = $this->participant(ParticipantType::Bot);

        $this->callGetProfile('Bearer '.$bot->getCode(), ['uuid' => $other->getUuid()->toRfc4122()]);

        self::assertStringNotContainsString($other->getUuid()->toRfc4122(), (string) $this->client->getResponse()->getContent());
    }

    public function testAnonymousCallIsRejected(): void
    {
        $data = $this->callGetProfile(null);

        self::assertResponseIsSuccessful();
        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertStringContainsString('Authentication required', $data['result']['content'][0]['text']);
    }

    public function testHumanCodeIsRejectedByTheFirewall(): void
    {
        $human = $this->participant(ParticipantType::Human);

        $this->callGetProfile('Bearer '.$human->getCode());

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString($human->getCode(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * Same kernel and container for every request (disableReboot), the path a FrankenPHP worker takes:
     * Kernel::handle() runs services_resetter before each new main request, which clears the token storage.
     * No identity may leak from one request into the next, whichever firewall authenticated it.
     */
    public function testConsecutiveRequestsDoNotShareAuthentication(): void
    {
        $first = $this->participant(ParticipantType::Bot, 'ES');
        $second = $this->participant(ParticipantType::Bot, 'PT');
        $human = $this->participant(ParticipantType::Human, 'FR');
        $this->em->clear();

        self::assertSame($first->getUuid()->toRfc4122(), $this->callGetProfile('Bearer '.$first->getCode())['result']['structuredContent']['uuid']);
        self::assertSame($second->getUuid()->toRfc4122(), $this->callGetProfile('Bearer '.$second->getCode())['result']['structuredContent']['uuid']);

        // Right after a bot: without a Bearer the MCP call is anonymous again...
        self::assertTrue($this->callGetProfile(null)['result']['isError']);
        // ...and the web API does not see the bot either.
        $this->client->request('GET', '/api/participants/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);

        // A human web session neither leaks into MCP (stateless firewall) nor survives into a Bearer call.
        $this->client->loginUser($human, 'main');
        $this->client->request('GET', '/api/participants/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame($human->getUuid()->toRfc4122(), json_decode((string) $this->client->getResponse()->getContent(), true)['uuid']);
        self::assertTrue($this->callGetProfile(null)['result']['isError']);
        self::assertSame($first->getUuid()->toRfc4122(), $this->callGetProfile('Bearer '.$first->getCode())['result']['structuredContent']['uuid']);

        // And the bot that just called does not leak back into the human's web request.
        $this->client->request('GET', '/api/participants/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame($human->getUuid()->toRfc4122(), json_decode((string) $this->client->getResponse()->getContent(), true)['uuid']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callGetProfile(?string $authorization, array $arguments = []): array
    {
        return $this->mcp('tools/call', ['name' => 'get_profile', 'arguments' => (object) $arguments], $authorization, ['HTTP_MCP_NAME' => 'get_profile']);
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $extraServer
     *
     * @return array<string, mixed>
     */
    private function mcp(string $method, array $params, ?string $authorization = null, array $extraServer = []): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION,
            'HTTP_MCP_METHOD' => $method,
            ...$extraServer,
        ];
        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];

        $this->client->request('POST', '/mcp', server: $server, content: json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            \JSON_THROW_ON_ERROR,
        ));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function participant(ParticipantType $type, ?string $countryCode = null): Participant
    {
        return static::getContainer()->get(ParticipantManager::class)->create($type, 'Test '.$type->value, $countryCode);
    }
}
