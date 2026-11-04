<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\Participant;
use App\Enum\EmojiPersonality;
use App\Enum\ParticipantType;
use App\Helper\ParticipantCodeGeneratorHelper;
use App\Repository\ParticipantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `register_agent` over the real HTTP endpoint, speaking MCP protocol revision 2026-07-28.
 */
class RegisterAgentToolTest extends WebTestCase
{
    private const string PROTOCOL_VERSION = '2026-07-28';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
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

        $tools = array_column($data['result']['tools'], null, 'name');
        self::assertArrayHasKey('register_agent', $tools);

        $schema = $tools['register_agent']['inputSchema'];
        self::assertSame(['name', 'countryCode'], array_keys($schema['properties']));
        self::assertEqualsCanonicalizing(['name', 'countryCode'], $schema['required']);
        // The client cannot smuggle a type, UUID or code into the call.
        self::assertFalse($schema['additionalProperties']);
    }

    public function testAnonymousAgentRegistersAsBot(): void
    {
        $data = $this->register(['name' => '  Robo   Pilot ', 'countryCode' => 'ES']);

        self::assertResponseIsSuccessful();
        $result = $data['result'];
        self::assertFalse($result['isError'] ?? false);

        $profile = $result['structuredContent'];
        self::assertSame(['uuid', 'name', 'emoji', 'countryCode', 'type', 'code'], array_keys($profile));
        self::assertSame('Robo Pilot', $profile['name']);
        self::assertSame('ES', $profile['countryCode']);
        self::assertSame('bot', $profile['type']);
        self::assertNotNull(EmojiPersonality::tryFrom($profile['emoji']));
        self::assertSame($profile['code'], static::getContainer()->get(ParticipantCodeGeneratorHelper::class)->normalize($profile['code']));
        self::assertSame($profile, json_decode($result['content'][0]['text'], true));

        $this->em->clear();
        $participant = static::getContainer()->get(ParticipantRepository::class)->findOneBy(['code' => $profile['code']]);
        self::assertInstanceOf(Participant::class, $participant);
        self::assertSame(ParticipantType::Bot, $participant->getType());
        self::assertSame($profile['uuid'], $participant->getUuid()->toRfc4122());
        self::assertSame($profile['emoji'], $participant->getEmoji());
    }

    public function testRegistrationsAreRateLimitedPerIp(): void
    {
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.agent_registration');
        $limiter = $factory->create('127.0.0.1');
        $limiter->reset();

        try {
            $limiter->consume($limiter->consume(0)->getRemainingTokens());
            $before = $this->countParticipants();

            $data = $this->register(['name' => 'Robo', 'countryCode' => 'ES']);

            self::assertTrue($data['result']['isError']);
            self::assertStringContainsString('Too many registration attempts', $data['result']['content'][0]['text']);
            self::assertSame($before, $this->countParticipants());
        } finally {
            $limiter->reset();
        }
    }

    /** The SDK debug-logs the structured content of plain return values; registerAgent() must not hand it the code. */
    public function testMcpLogsNeverCarryTheCode(): void
    {
        // Captures every record the real "mcp" channel logger emits, debug included, whatever the handlers.
        /** @var Logger $logger */
        $logger = static::getContainer()->get('monolog.logger.mcp');
        $logger->pushHandler($records = new TestHandler(Level::Debug));

        $code = $this->register(['name' => 'Robo', 'countryCode' => 'ES'])['result']['structuredContent']['code'];

        self::assertTrue($records->hasDebugThatContains('Tool executed successfully'));
        foreach ($records->getRecords() as $record) {
            self::assertStringNotContainsString($code, json_encode([$record->message, $record->context, $record->extra], \JSON_PARTIAL_OUTPUT_ON_ERROR), $record->message);
        }
    }

    public function testNoSessionAndNoSharedCache(): void
    {
        $this->register(['name' => 'Robo', 'countryCode' => 'ES']);

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasCookie('PHPSESSID');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('public'));
    }

    public function testReturnedCodeAuthenticatesTheBotButGetProfileNeverReturnsIt(): void
    {
        $code = $this->register(['name' => 'Robo', 'countryCode' => 'FR'])['result']['structuredContent']['code'];

        $data = $this->mcp('tools/call', ['name' => 'get_profile', 'arguments' => new \stdClass()], 'Bearer '.$code, ['HTTP_MCP_NAME' => 'get_profile']);

        self::assertSame('Robo', $data['result']['structuredContent']['name']);
        self::assertStringNotContainsString($code, (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'empty name' => [['name' => '', 'countryCode' => 'ES'], 'name: This value should not be blank.'];
        yield 'blank name' => [['name' => '   ', 'countryCode' => 'ES'], 'name: This value should not be blank.'];
        yield 'whitespace-only name' => [['name' => " \t\n ", 'countryCode' => 'ES'], 'name: This value should not be blank.'];
        yield 'name too long' => [['name' => str_repeat('a', Participant::NAME_MAX_LENGTH + 1), 'countryCode' => 'ES'], 'name: This value is too long.'];
        yield 'blank country' => [['name' => 'Robo', 'countryCode' => ''], 'countryCode: The country is required.'];
        yield 'lowercase country' => [['name' => 'Robo', 'countryCode' => 'es'], 'countryCode: The country code must be an uppercase ISO 3166-1 alpha-2 code (e.g. ES).'];
        yield 'unknown country' => [['name' => 'Robo', 'countryCode' => 'XX'], 'countryCode: The country code must be an uppercase'];
        yield 'alpha-3 country' => [['name' => 'Robo', 'countryCode' => 'ESP'], 'countryCode: The country code must be an uppercase'];
        yield 'both invalid' => [['name' => '', 'countryCode' => 'XX'], 'name: This value should not be blank. countryCode: The country code must be an uppercase'];
    }

    public function testNameAtMaximumLengthIsAccepted(): void
    {
        $name = str_repeat('a', Participant::NAME_MAX_LENGTH);

        $data = $this->register(['name' => $name, 'countryCode' => 'ES']);

        self::assertFalse($data['result']['isError'] ?? false);
        self::assertSame($name, $data['result']['structuredContent']['name']);
    }

    /** @param array<string, string> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidArguments')]
    public function testValidationErrorsAreToolErrors(array $arguments, string $expected): void
    {
        $before = $this->countParticipants();

        $data = $this->register($arguments);

        self::assertTrue($data['result']['isError']);
        self::assertArrayNotHasKey('structuredContent', $data['result']);
        self::assertStringContainsString($expected, $data['result']['content'][0]['text']);
        self::assertSame($before, $this->countParticipants());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedArguments(): iterable
    {
        yield 'missing country' => [['name' => 'Robo']];
        yield 'non-string name' => [['name' => 42, 'countryCode' => 'ES']];
        yield 'chosen type' => [['name' => 'Robo', 'countryCode' => 'ES', 'type' => 'human']];
        yield 'chosen uuid' => [['name' => 'Robo', 'countryCode' => 'ES', 'uuid' => '0190f1b2-0000-7000-8000-000000000000']];
        yield 'chosen code' => [['name' => 'Robo', 'countryCode' => 'ES', 'code' => 'AAAA-AAAA-AAAA-AAAA']];
    }

    /** @param array<string, mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedArguments')]
    public function testArgumentsOutsideTheSchemaAreRejected(array $arguments): void
    {
        $before = $this->countParticipants();

        $data = $this->register($arguments);

        self::assertSame(-32602, $data['error']['code']);
        self::assertSame($before, $this->countParticipants());
    }

    private function countParticipants(): int
    {
        return static::getContainer()->get(ParticipantRepository::class)->count([]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function register(array $arguments): array
    {
        return $this->mcp('tools/call', ['name' => 'register_agent', 'arguments' => $arguments], null, ['HTTP_MCP_NAME' => 'register_agent']);
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
}
